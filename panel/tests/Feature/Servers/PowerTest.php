<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\ServerPower;
use App\Enums\ServerMoveKind;
use App\Enums\ServerMoveStatus;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class PowerTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function power(string $action): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.power', ['server' => $this->server, 'action' => $action]));
    }

    public function test_admins_can_stop_and_start_a_server()
    {
        $this->power('stop')->assertRedirect(route('admin.servers.show', $this->server));
        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);

        $this->power('start');
        $this->assertSame('running', $this->proxmox->vms[100]['status']);

        $this->assertSame(['stop', 'start'], Task::query()->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(2, Task::query()->where('status', TaskStatus::Succeeded)->count());
        $this->assertSame($this->admin->id, Task::query()->firstOrFail()->user_id);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status, 'Power actions never change the server status.');
    }

    public function test_a_shutdown_asks_the_guest_and_is_never_forced()
    {
        $this->power('shutdown');

        $write = collect($this->proxmox->writes)->firstWhere('path', '/nodes/pve1/qemu/100/status/shutdown');
        $this->assertNotNull($write);
        $this->assertArrayNotHasKey('forceStop', $write['data']);
        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);
    }

    public function test_actions_do_nothing_when_the_vm_is_already_in_that_state()
    {
        $this->power('start');

        $this->assertNotContains('POST /nodes/pve1/qemu/100/status/start', $this->proxmox->writePaths());
        $this->assertSame(TaskStatus::Succeeded, Task::query()->sole()->status);
    }

    public function test_rebooting_a_stopped_server_starts_it()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->power('reboot');

        $this->assertContains('POST /nodes/pve1/qemu/100/status/start', $this->proxmox->writePaths());
        $this->assertSame('running', $this->proxmox->vms[100]['status']);
    }

    public function test_power_actions_are_audited()
    {
        $this->power('reboot');

        $log = AuditLog::query()->sole();
        $this->assertSame('server.power', $log->action);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('reboot', $log->properties['action'] ?? null);
        $this->assertTrue($log->subject?->is($this->server));
    }

    public function test_suspended_servers_cannot_be_powered_on()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->power('start')->assertRedirect(route('admin.servers.show', $this->server));

        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_unknown_actions_are_not_found()
    {
        $this->power('hibernate')->assertNotFound();
    }

    public function test_only_one_task_runs_at_a_time()
    {
        $task = new Task;
        $task->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Create, 'status' => TaskStatus::Running, 'steps' => []])->save();

        $this->power('stop')->assertRedirect(route('admin.servers.show', $this->server));

        $this->assertSame(1, Task::query()->count());
        $this->assertSame('running', $this->proxmox->vms[100]['status']);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_the_page_loads_power_state_and_guest_agent_reachability_after_rendering()
    {
        $this->proxmox->agentDown[100] = true;

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('power')
                ->missing('agent')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('power', 'running')
                    ->where('agent', false)));
    }

    public function test_the_guest_agent_of_a_vm_stopped_in_proxmox_is_not_asked()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('power', 'stopped')
                    ->where('agent', null)));

        $this->assertNotContains('POST /nodes/pve1/qemu/100/agent/ping', $this->proxmox->writePaths());
    }

    /**
     * @param  array{state: string, node: string|null}  $vm
     */
    private function assertLiveState(?string $power, array $vm, ?bool $agent): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('power', $power)
                    ->where('vm', $vm)
                    ->where('agent', $agent)));
    }

    public function test_the_page_says_when_proxmox_has_no_vm_for_the_server()
    {
        unset($this->proxmox->vms[100]);

        $this->assertLiveState(null, ['state' => 'missing', 'node' => null], null);

        $this->assertNotContains('POST /nodes/pve1/qemu/100/agent/ping', $this->proxmox->writePaths());
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status, 'Drift is only reported, never acted on (ADR 0005).');
    }

    public function test_a_vm_migrated_to_another_node_in_proxmox_is_not_reported_as_missing()
    {
        $this->proxmox->vms[100]['node'] = 'pve2';
        $this->proxmox->strictNode = [100];

        $this->assertLiveState(null, ['state' => 'elsewhere', 'node' => 'pve2'], null);
    }

    public function test_a_move_the_panel_has_not_recorded_is_not_reported_as_missing()
    {
        unset($this->proxmox->vms[100]);
        // Proxmox moved the VM, then a later step failed (Finish the move, docs/adr/0037 §7).
        (new ServerMove)->forceFill([
            'server_id' => $this->server->id,
            'kind' => ServerMoveKind::Node,
            'status' => ServerMoveStatus::Failed,
            'source_location_id' => $this->server->location_id,
            'source_cluster_id' => $this->server->cluster_id,
            'source_node_id' => $this->server->node_id,
            'source_vmid' => 100,
            'target_location_id' => $this->server->location_id,
            'target_cluster_id' => $this->server->cluster_id,
            'target_node_id' => $this->server->node_id,
            'target_vmid' => 100,
            'migrated_at' => now(),
        ])->save();

        $this->assertLiveState(null, ['state' => 'moving', 'node' => null], null);
    }

    public function test_the_guest_agent_is_not_asked_while_the_power_state_is_unknown()
    {
        // QemuResource::status() says "unknown" when Proxmox leaves the field out.
        $this->proxmox->vms[100]['status'] = 'unknown';

        $this->assertLiveState(null, ['state' => 'present', 'node' => null], null);

        $this->assertNotContains('POST /nodes/pve1/qemu/100/agent/ping', $this->proxmox->writePaths());
    }

    public function test_the_api_reports_an_unknown_power_state_for_a_missing_vm()
    {
        unset($this->proxmox->vms[100]);

        $this->assertNull(app(ServerPower::class)->state($this->server));
    }
}
