<?php

namespace Tests\Feature\Usage;

use App\Domain\Usage\TrafficPeriods;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Counting what servers use (docs/adr/0039).
 */
class UsageCollectorTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
        $this->counters(netin: 1_000_000, netout: 500_000, uptime: 3600);
    }

    private function counters(int $netin, int $netout, int $uptime, int $diskread = 0, int $diskwrite = 0, float $cpu = 0.5): void
    {
        $this->proxmox->stats[100] = [
            'cpu' => $cpu,
            'mem' => 1024 ** 3,
            'maxmem' => 4 * 1024 ** 3,
            'netin' => $netin,
            'netout' => $netout,
            'diskread' => $diskread,
            'diskwrite' => $diskwrite,
            'uptime' => $uptime,
        ];
    }

    private function collect(): void
    {
        $this->artisan('usage:collect')->assertSuccessful();
    }

    private function period(): ?TrafficPeriod
    {
        return TrafficPeriod::query()->where('server_id', $this->server->id)->first();
    }

    public function test_the_first_collection_counts_nothing_and_keeps_the_counters()
    {
        $this->collect();

        $sample = UsageSample::query()->sole();
        $this->assertSame('running', $sample->status);
        $this->assertSame(50.0, $sample->cpu_percent);
        $this->assertSame(25.0, $sample->memory_percent);
        $this->assertNull($sample->net_in_rate, 'What came before the first collection is unknown.');
        $this->assertNull($this->period());
        $this->assertSame(1_000_000, $this->server->refresh()->usage_counters['net_in'] ?? null);
    }

    public function test_later_collections_add_the_traffic_since_the_previous_one()
    {
        $this->collect();
        $this->travel(5)->minutes();
        $this->counters(netin: 4_000_000, netout: 2_000_000, uptime: 3900, diskread: 3_000_000, diskwrite: 0);
        $this->collect();

        $period = $this->period();
        $this->assertNotNull($period);
        $this->assertSame('2026-10-01', $period->period_start->toDateString());
        $this->assertSame(3_000_000, $period->in_bytes);
        $this->assertSame(1_500_000, $period->out_bytes);

        $sample = UsageSample::query()->latest('id')->firstOrFail();
        $this->assertSame(10_000.0, $sample->net_in_rate);
        $this->assertSame(5_000.0, $sample->net_out_rate);
        $this->assertSame(10_000.0, $sample->disk_read_rate);

        $this->travel(5)->minutes();
        $this->counters(netin: 5_000_000, netout: 2_500_000, uptime: 4200);
        $this->collect();

        $this->assertSame(4_000_000, $this->period()?->in_bytes);
        $this->assertSame(2_000_000, $this->period()?->out_bytes);
        $this->assertSame(['in_bytes' => 4_000_000, 'out_bytes' => 2_000_000, 'counted_bytes' => 6_000_000], array_intersect_key(TrafficPeriods::usage($this->server), array_flip(['in_bytes', 'out_bytes', 'counted_bytes'])));
    }

    public function test_counters_of_a_restarted_vm_are_counted_from_zero()
    {
        $this->collect();
        $this->travel(5)->minutes();
        // Restarted: shorter uptime, counters from zero again.
        $this->counters(netin: 200_000, netout: 100_000, uptime: 60);
        $this->collect();

        $this->assertSame(200_000, $this->period()?->in_bytes);
        $this->assertSame(100_000, $this->period()?->out_bytes);
    }

    public function test_a_new_month_starts_a_new_period_on_the_reset_day()
    {
        config(['proxmotion.usage.traffic_reset_day' => 20]);
        $this->assertSame('2026-09-20', TrafficPeriods::start()->toDateString());
        $this->assertSame('2026-10-20', TrafficPeriods::end(TrafficPeriods::start())->toDateString());

        $this->collect();
        $this->travelTo(Carbon::parse('2026-10-20 00:00:00'));
        $this->counters(netin: 2_000_000, netout: 500_000, uptime: 4000);
        $this->collect();

        $this->assertSame('2026-10-20', $this->period()?->period_start->toDateString());
    }

    public function test_the_counted_traffic_follows_the_setting()
    {
        $this->assertSame(30, TrafficPeriods::counted(10, 20));

        config(['proxmotion.usage.traffic_counted' => 'out']);
        $this->assertSame(20, TrafficPeriods::counted(10, 20));

        config(['proxmotion.usage.traffic_counted' => 'in']);
        $this->assertSame(10, TrafficPeriods::counted(10, 20));
    }

    public function test_a_stopped_vm_uses_no_cpu_or_memory()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';
        $this->collect();

        $sample = UsageSample::query()->sole();
        $this->assertSame('stopped', $sample->status);
        $this->assertSame(0.0, $sample->cpu_percent);
        $this->assertSame(0.0, $sample->memory_percent);
    }

    public function test_servers_without_a_vm_are_not_sampled()
    {
        $this->proxmox->addVm(101, 'old.example.com');
        Server::factory()->for($this->node)->status(ServerStatus::Terminated)->create(['vmid' => 101]);
        Server::factory()->for($this->node)->create(['vmid' => 555]);

        $this->collect();

        $this->assertSame([$this->server->id], UsageSample::query()->pluck('server_id')->all());
    }

    public function test_samples_are_pruned_after_two_days()
    {
        $this->collect();
        $this->travel(UsageSample::KEEP_HOURS + 1)->hours();
        $this->collect();

        $this->artisan('model:prune', ['--model' => [UsageSample::class]])->assertSuccessful();

        $this->assertSame(1, UsageSample::query()->count());
    }
}
