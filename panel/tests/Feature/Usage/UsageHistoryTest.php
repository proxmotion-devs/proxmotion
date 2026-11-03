<?php

namespace Tests\Feature\Usage;

use App\Domain\Usage\UsageHistory;
use App\Domain\Usage\UsageRollups;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageRollup;
use App\Models\UsageSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Hours and days rolled up from the samples, and the history of a server (docs/adr/0050).
 */
class UsageHistoryTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private int $netIn = 0;

    private int $uptime = 3600;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 11:50:00'));
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
    }

    /**
     * One collection after the counters grew by $in received bytes (and half of it sent).
     */
    private function collect(int $in = 0, float $cpu = 0.5): void
    {
        $this->netIn += $in;
        $this->uptime += 300;
        $this->proxmox->stats[100] = [
            'cpu' => $cpu,
            'mem' => 1024 ** 3,
            'maxmem' => 4 * 1024 ** 3,
            'netin' => $this->netIn,
            'netout' => intdiv($this->netIn, 2),
            'diskread' => 0,
            'diskwrite' => 0,
            'uptime' => $this->uptime,
        ];

        $this->artisan('usage:collect')->assertSuccessful();
    }

    private function rollup(string $resolution, string $start): UsageRollup
    {
        return UsageRollup::query()
            ->where('server_id', $this->server->id)
            ->where('resolution', $resolution)
            ->where('starts_at', Carbon::parse($start))
            ->sole();
    }

    public function test_samples_keep_their_bytes_and_are_rolled_up_into_hours_and_days()
    {
        $this->collect(); // 11:50, the first: counts nothing.
        $this->travel(5)->minutes();
        $this->collect(in: 3_000_000, cpu: 0.2); // 11:55
        $this->travel(5)->minutes();
        $this->collect(in: 1_000_000, cpu: 0.6); // 12:00

        $sample = UsageSample::query()->latest('id')->firstOrFail();
        $this->assertSame(1_000_000, $sample->net_in_bytes);
        $this->assertSame(500_000, $sample->net_out_bytes);

        $eleven = $this->rollup(UsageRollup::HOUR, '2026-10-15 11:00:00');
        $this->assertSame(2, $eleven->samples);
        $this->assertSame(35.0, $eleven->cpu_percent);
        $this->assertSame(25.0, $eleven->memory_percent);
        $this->assertSame(3_000_000, $eleven->in_bytes);
        $this->assertSame(1_500_000, $eleven->out_bytes);

        $noon = $this->rollup(UsageRollup::HOUR, '2026-10-15 12:00:00');
        $this->assertSame(1, $noon->samples);
        $this->assertSame(60.0, $noon->cpu_percent);

        // The day weighs each hour by its samples: (35 × 2 + 60) / 3.
        $day = $this->rollup(UsageRollup::DAY, '2026-10-15 00:00:00');
        $this->assertSame(3, $day->samples);
        $this->assertSame(43.3, $day->cpu_percent);
        $this->assertSame(4_000_000, $day->in_bytes);
        $this->assertSame(2_000_000, $day->out_bytes);
    }

    public function test_repeating_a_rollup_counts_nothing_twice()
    {
        $this->collect();
        $this->travel(5)->minutes();
        $this->collect(in: 3_000_000);

        app(UsageRollups::class)->roll([$this->server->id]);
        app(UsageRollups::class)->roll([$this->server->id]);

        $this->assertSame(2, UsageRollup::query()->count(), 'One hour and one day.');
        $this->assertSame(3_000_000, $this->rollup(UsageRollup::DAY, '2026-10-15 00:00:00')->in_bytes);
    }

    public function test_the_last_samples_of_an_hour_are_counted_after_it_ended()
    {
        $this->collect(); // 11:50
        $this->travel(5)->minutes();
        $this->collect(in: 1_000_000); // 11:55
        // A sample of 11:58 that the 11:55 run did not see yet.
        UsageSample::query()->insert(['server_id' => $this->server->id, 'sampled_at' => now()->addMinutes(3), 'status' => 'running', 'net_in_bytes' => 500_000, 'net_out_bytes' => 0]);
        $this->travel(5)->minutes();
        $this->collect(in: 0); // 12:00

        $this->assertSame(1_500_000, $this->rollup(UsageRollup::HOUR, '2026-10-15 11:00:00')->in_bytes);
    }

    public function test_hours_and_days_are_pruned_after_their_time()
    {
        UsageRollup::query()->insert([
            ['server_id' => $this->server->id, 'resolution' => 'hour', 'starts_at' => now()->subDays(36), 'samples' => 12],
            ['server_id' => $this->server->id, 'resolution' => 'hour', 'starts_at' => now()->subDays(30), 'samples' => 12],
            ['server_id' => $this->server->id, 'resolution' => 'day', 'starts_at' => now()->subDays(36), 'samples' => 288],
            ['server_id' => $this->server->id, 'resolution' => 'day', 'starts_at' => now()->subDays(401), 'samples' => 288],
        ]);

        $this->artisan('model:prune', ['--model' => [UsageRollup::class]])->assertSuccessful();

        $this->assertSame(
            [['hour', 30], ['day', 36]],
            UsageRollup::query()->orderBy('id')->get()->map(fn (UsageRollup $rollup) => [$rollup->resolution, (int) round($rollup->starts_at->diffInDays(now()))])->all(),
        );
    }

    public function test_the_day_range_gives_samples_and_hourly_traffic()
    {
        $this->collect();
        $this->travel(5)->minutes();
        $this->collect(in: 3_000_000);
        $this->travel(5)->minutes();
        $this->collect(in: 1_000_000);

        $history = app(UsageHistory::class)->for($this->server, 'day');

        $this->assertSame('day', $history['range']);
        $this->assertSame(300, $history['interval_seconds']);
        $this->assertCount(3, $history['points']);
        $this->assertSame('2026-10-15T11:55:00+00:00', $history['points'][1]['time']);
        $this->assertSame(3_000_000, $history['points'][1]['in_bytes']);
        $this->assertSame('hour', $history['traffic']['interval']);
        $this->assertSame(
            [['2026-10-15T11:00:00+00:00', '2026-10-15T12:00:00+00:00', 4_500_000], ['2026-10-15T12:00:00+00:00', '2026-10-15T13:00:00+00:00', 1_500_000]],
            array_map(fn (array $bucket) => [$bucket['start'], $bucket['end'], $bucket['counted_bytes']], $history['traffic']['buckets']),
        );
    }

    public function test_longer_ranges_give_hours_and_days()
    {
        UsageRollup::query()->insert([
            ['server_id' => $this->server->id, 'resolution' => 'hour', 'starts_at' => '2026-10-07 10:00:00', 'samples' => 12, 'cpu_percent' => 10, 'in_bytes' => 1, 'out_bytes' => 0],
            ['server_id' => $this->server->id, 'resolution' => 'hour', 'starts_at' => '2026-10-15 10:00:00', 'samples' => 12, 'cpu_percent' => 20, 'in_bytes' => 2, 'out_bytes' => 0],
            ['server_id' => $this->server->id, 'resolution' => 'day', 'starts_at' => '2026-10-07 00:00:00', 'samples' => 288, 'cpu_percent' => 30, 'in_bytes' => 3, 'out_bytes' => 0],
            ['server_id' => $this->server->id, 'resolution' => 'day', 'starts_at' => '2026-10-14 00:00:00', 'samples' => 288, 'cpu_percent' => 40, 'in_bytes' => 4, 'out_bytes' => 1],
        ]);

        $week = app(UsageHistory::class)->for($this->server, 'week');
        $this->assertSame(3600, $week['interval_seconds']);
        $this->assertSame([20.0], array_column($week['points'], 'cpu_percent'), 'The 7th of October is more than a week ago.');
        $this->assertSame('day', $week['traffic']['interval']);
        $this->assertSame([['2026-10-14T00:00:00+00:00', '2026-10-15T00:00:00+00:00', 5]], array_map(fn (array $bucket) => [$bucket['start'], $bucket['end'], $bucket['counted_bytes']], $week['traffic']['buckets']));

        $month = app(UsageHistory::class)->for($this->server, 'month');
        $this->assertSame([10.0, 20.0], array_column($month['points'], 'cpu_percent'));

        $year = app(UsageHistory::class)->for($this->server, 'year');
        $this->assertSame(86400, $year['interval_seconds']);
        $this->assertSame([30.0, 40.0], array_column($year['points'], 'cpu_percent'));
    }

    public function test_the_year_range_gives_the_traffic_of_the_last_twelve_periods()
    {
        config(['proxmotion.usage.traffic_counted' => 'out']);
        TrafficPeriod::query()->insert([
            ['server_id' => $this->server->id, 'period_start' => '2025-10-01', 'in_bytes' => 1, 'out_bytes' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['server_id' => $this->server->id, 'period_start' => '2025-11-01', 'in_bytes' => 2, 'out_bytes' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['server_id' => $this->server->id, 'period_start' => '2026-10-01', 'in_bytes' => 4, 'out_bytes' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $traffic = app(UsageHistory::class)->for($this->server, 'year')['traffic'];

        $this->assertSame('period', $traffic['interval']);
        $this->assertSame(
            [['2025-11-01T00:00:00+00:00', '2025-12-01T00:00:00+00:00', 3], ['2026-10-01T00:00:00+00:00', '2026-11-01T00:00:00+00:00', 5]],
            array_map(fn (array $bucket) => [$bucket['start'], $bucket['end'], $bucket['counted_bytes']], $traffic['buckets']),
        );
    }

    public function test_the_server_page_asks_for_a_range()
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->collect();

        $this->actingAs($admin)->getJson(route('admin.servers.usage', ['server' => $this->server, 'range' => 'week']))
            ->assertOk()
            ->assertJsonPath('range', 'week')
            ->assertJsonPath('interval_seconds', 3600)
            ->assertJsonPath('traffic.interval', 'day')
            ->assertJsonPath('current.quota_bytes', $this->server->traffic_bytes);

        $this->actingAs($admin)->getJson(route('admin.servers.usage', ['server' => $this->server, 'range' => 'decade']))
            ->assertUnprocessable();
    }
}
