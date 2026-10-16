<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->customer)->for($this->node)->create(['vmid' => 100]);
    }

    private function power(string $action, ?User $user = null, string $route = 'servers.power'): TestResponse
    {
        return $this->actingAs($user ?? $this->customer)
            ->from(route('servers.show', $this->server))
            ->post(route($route, ['server' => $this->server, 'action' => $action]));
    }

    public function test_customers_can_stop_and_start_their_server()
    {
        $this->power('stop')->assertRedirect(route('servers.show', $this->server));
        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);

        $this->power('start');
        $this->assertSame('running', $this->proxmox->vms[100]['status']);

        $this->assertSame(['stop', 'start'], Task::query()->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(2, Task::query()->where('status', TaskStatus::Succeeded)->count());
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
        $this->assertSame($this->customer->id, $log->user_id);
        $this->assertSame('reboot', $log->properties['action'] ?? null);
        $this->assertTrue($log->subject?->is($this->server));
    }

    public function test_customers_cannot_power_another_users_server()
    {
        $this->server->forceFill(['user_id' => User::factory()->create()->id])->save();

        $this->power('stop')->assertForbidden();

        $this->assertSame(0, Task::query()->count());
        $this->assertSame('running', $this->proxmox->vms[100]['status']);
    }

    public function test_suspended_servers_cannot_be_powered_on()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->power('start')->assertForbidden();

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

        $this->power('stop')->assertRedirect(route('servers.show', $this->server));

        $this->assertSame(1, Task::query()->count());
        $this->assertSame('running', $this->proxmox->vms[100]['status']);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_admins_can_power_any_active_server()
    {
        $admin = User::factory()->admin()->withTwoFactor()->create();

        $this->power('stop', $admin, 'admin.servers.power');

        $this->assertSame('stopped', $this->proxmox->vms[100]['status']);
        $this->assertSame($admin->id, Task::query()->sole()->user_id);
    }

    public function test_customers_cannot_use_the_admin_power_route()
    {
        $this->power('stop', route: 'admin.servers.power')->assertForbidden();

        $this->assertSame('running', $this->proxmox->vms[100]['status']);
    }

    public function test_the_admin_page_loads_power_state_and_guest_agent_reachability()
    {
        $this->proxmox->agentDown[100] = true;

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('agent')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('power', 'running')
                    ->where('agent', false)));
    }

    public function test_the_page_loads_the_power_state_after_rendering()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->actingAs($this->customer)
            ->get(route('servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('power')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('power', 'stopped')));
    }
}
