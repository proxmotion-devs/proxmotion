<?php

namespace Tests\Feature\Usage;

use App\Enums\ServerStatus;
use App\Enums\SuspensionCause;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\Server;
use App\Models\Task;
use App\Models\UsageViolation;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Traffic quotas and usage limits (docs/adr/0039).
 */
class UsageLimitsTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private int $netOut = 0;

    private int $uptime = 3600;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake([DeliverWebhook::class]);
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100, 'traffic_bytes' => 10 * 1000 ** 3]);
    }

    /**
     * One collection, after the server sent $sentBytes more and used $cpu of its vCPUs; then 5 minutes pass.
     */
    private function collect(int $sentBytes = 0, float $cpu = 0.1): void
    {
        $this->netOut += $sentBytes;
        $this->uptime += 300;
        $this->proxmox->stats[100] = [
            'cpu' => $cpu,
            'mem' => 1024 ** 3,
            'maxmem' => 4 * 1024 ** 3,
            'netin' => 0,
            'netout' => $this->netOut,
            'diskread' => 0,
            'diskwrite' => 0,
            'uptime' => $this->uptime,
        ];

        $this->artisan('usage:collect')->assertSuccessful();
        $this->travel(5)->minutes();
    }

    private function violation(): ?UsageViolation
    {
        return UsageViolation::query()->latest('id')->first();
    }

    public function test_a_server_over_its_quota_is_only_recorded_by_default()
    {
        $this->collect();
        $this->collect(11 * 1000 ** 3);

        $violation = $this->violation();
        $this->assertNotNull($violation);
        $this->assertSame(UsageKind::Traffic, $violation->kind);
        $this->assertSame(UsageAction::None, $violation->action);
        $this->assertSame('2026-10-01', $violation->period_start?->toDateString());
        $this->assertSame(11.0 * 1000 ** 3, $violation->value);
        $this->assertNull($violation->acted_at);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_server_over_its_quota_is_suspended_and_unsuspended_in_the_next_period()
    {
        config(['proxmotion.usage.traffic_action' => 'suspend']);

        $this->collect();
        $this->collect(9 * 1000 ** 3);
        $this->assertNull($this->violation());

        $this->collect(2 * 1000 ** 3);

        $this->server->refresh();
        $this->assertSame(ServerStatus::Suspended, $this->server->status);
        $this->assertSame(SuspensionCause::Traffic, $this->server->suspension_cause);
        $this->assertSame('Traffic quota exceeded: 11 GB of 10 GB.', $this->server->suspension_reason);
        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);
        $this->assertNotNull($this->violation()?->acted_at);
        $this->assertSame(TaskType::Suspend, $this->violation()?->task?->type);
        $this->assertNull($this->violation()?->task?->user_id);

        $audit = AuditLog::query()->where('action', 'server.usage_action')->sole();
        $this->assertNull($audit->user_id);
        $this->assertSame('suspend', $audit->properties['action'] ?? null);

        // Still over the quota: nothing more happens.
        $this->collect();
        $this->assertSame(1, Task::query()->count());

        // November: back within the quota.
        $this->travelTo(Carbon::parse('2026-11-01 00:01:00'));
        $this->collect();

        $this->server->refresh();
        $this->assertSame(ServerStatus::Active, $this->server->status);
        $this->assertNull($this->server->suspension_cause);
        $this->assertNull($this->server->suspension_reason);
        $this->assertNotNull(UsageViolation::query()->sole()->resolved_at);
        $this->assertTrue(AuditLog::query()->where('action', 'server.usage_unsuspended')->exists());
    }

    public function test_a_server_unsuspended_by_an_administrator_runs_until_the_period_ends()
    {
        config(['proxmotion.usage.traffic_action' => 'suspend']);
        $this->collect();
        $this->collect(11 * 1000 ** 3);
        $this->assertSame(ServerStatus::Suspended, $this->server->refresh()->status);

        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin)->post(route('admin.servers.unsuspend', $this->server))->assertRedirect();
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);

        $this->collect(1000 ** 3);

        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $this->assertSame(2, Task::query()->count());
        $this->assertNull($this->violation()?->resolved_at, 'Still over the quota.');
    }

    public function test_raising_the_quota_unsuspends_the_server()
    {
        config(['proxmotion.usage.traffic_action' => 'suspend']);
        $this->collect();
        $this->collect(11 * 1000 ** 3);
        $this->assertSame(ServerStatus::Suspended, $this->server->refresh()->status);

        $this->server->forceFill(['traffic_bytes' => 20 * 1000 ** 3])->save();
        $this->collect();

        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
    }

    public function test_exempt_servers_are_recorded_but_never_suspended()
    {
        config(['proxmotion.usage.traffic_action' => 'suspend']);
        $this->server->forceFill(['usage_exempt' => true])->save();

        $this->collect();
        $this->collect(11 * 1000 ** 3);

        $this->assertNotNull($this->violation());
        $this->assertNull($this->violation()?->acted_at);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
    }

    public function test_a_busy_server_is_suspended_once_its_task_finished()
    {
        config(['proxmotion.usage.traffic_action' => 'suspend']);
        $task = new Task;
        $task->forceFill(['server_id' => $this->server->id, 'type' => TaskType::ChangeResources, 'status' => TaskStatus::Running, 'steps' => []])->save();

        $this->collect();
        $this->collect(11 * 1000 ** 3);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $this->assertNull($this->server->suspension_cause);

        $task->forceFill(['status' => TaskStatus::Succeeded])->save();
        $this->collect();

        $this->assertSame(ServerStatus::Suspended, $this->server->refresh()->status);
    }

    public function test_a_server_busy_for_a_whole_window_is_stopped()
    {
        config([
            'proxmotion.usage.cpu_percent' => 90,
            'proxmotion.usage.window_minutes' => 15,
            'proxmotion.usage.action' => 'stop',
        ]);

        // Three samples, each standing for 5 minutes, make the window.
        $this->collect(cpu: 0.95);
        $this->collect(cpu: 0.95);
        $this->assertNull($this->violation(), 'Not yet a whole window.');

        $this->collect(cpu: 0.95);

        $violation = $this->violation();
        $this->assertNotNull($violation);
        $this->assertSame(UsageKind::Cpu, $violation->kind);
        $this->assertSame(95.0, $violation->value);
        $this->assertSame(90.0, $violation->threshold);
        $this->assertSame(15, $violation->window_minutes);
        $this->assertSame(TaskType::Stop, $violation->task?->type);
        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status, 'A stopped server stays active: the customer may start it again.');

        // Stopped, the average falls and the violation ends.
        $this->collect();
        $this->collect();
        $this->collect();

        $this->assertNotNull($violation->refresh()->resolved_at);
    }

    public function test_a_server_over_a_usage_limit_stays_suspended()
    {
        config([
            'proxmotion.usage.cpu_percent' => 90,
            'proxmotion.usage.window_minutes' => 15,
            'proxmotion.usage.action' => 'suspend',
        ]);

        foreach (range(1, 4) as $collection) {
            $this->collect(cpu: 1.0);
        }

        $this->server->refresh();
        $this->assertSame(ServerStatus::Suspended, $this->server->status);
        $this->assertSame(SuspensionCause::Usage, $this->server->suspension_cause);
        $this->assertSame('Usage limit exceeded: CPU averaged 100% over 15 minutes (limit 90%).', $this->server->suspension_reason);

        $this->travelTo(Carbon::parse('2026-11-02 00:00:00'));

        foreach (range(1, 4) as $collection) {
            $this->collect();
        }

        $this->assertSame(ServerStatus::Suspended, $this->server->refresh()->status);
        $this->assertNotNull($this->violation()?->resolved_at);
    }

    public function test_limits_that_are_off_open_nothing_and_close_what_is_open()
    {
        config(['proxmotion.usage.window_minutes' => 15, 'proxmotion.usage.cpu_percent' => 50]);

        foreach (range(1, 4) as $collection) {
            $this->collect(cpu: 0.8);
        }

        $this->assertSame(UsageKind::Cpu, $this->violation()?->kind);

        config(['proxmotion.usage.cpu_percent' => null]);
        $this->collect(cpu: 0.8);

        $this->assertNotNull($this->violation()?->resolved_at);
        $this->assertSame(1, UsageViolation::query()->count());
    }

    public function test_billing_systems_hear_about_violations()
    {
        $system = BillingSystem::factory()->create();
        WebhookEndpoint::factory()->for($system)->create();
        $this->server->forceFill(['billing_system_id' => $system->id, 'external_id' => '1001'])->save();

        $this->collect();
        $this->collect(11 * 1000 ** 3);

        $delivery = WebhookDelivery::query()->where('event', 'server.usage_exceeded')->sole();
        $payload = json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('1001', $payload['data']['server']['external_id']);
        $this->assertSame('traffic', $payload['data']['violation']['kind']);
        $this->assertSame('bytes', $payload['data']['violation']['unit']);
        $this->assertSame('none', $payload['data']['violation']['action']);
    }
}
