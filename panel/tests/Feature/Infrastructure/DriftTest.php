<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Infrastructure\DriftDetector;
use App\Enums\DriftType;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class DriftTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order());
        $this->admin = User::factory()->admin()->withTwoFactor()->create();
    }

    private function detect(): void
    {
        $guests = app(ProxmoxClientFactory::class)->forCluster($this->cluster)->cluster()->guests();

        app(DriftDetector::class)->detect($this->cluster, $guests);
    }

    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    private function drift(DriftType $type): ServerDrift
    {
        return ServerDrift::query()->where('type', $type)->sole();
    }

    public function test_a_freshly_provisioned_server_has_no_drift()
    {
        $this->detect();

        $this->assertSame(0, ServerDrift::query()->count());
    }

    public function test_a_deleted_vm_is_reported_and_the_server_can_be_marked_as_failed()
    {
        unset($this->proxmox->vms[$this->server->vmid]);

        $this->detect();
        $drift = $this->drift(DriftType::MissingVm);
        $this->assertSame($this->server->id, $drift->server_id);

        $this->actingAs($this->admin)->post(route('admin.drifts.mark-error', $drift));

        $this->assertSame(ServerStatus::Error, $this->server->refresh()->status);
        $this->assertSame('marked_error', $drift->refresh()->resolution);
        $this->assertSame('drift.marked_error', AuditLog::query()->latest('id')->firstOrFail()->action);
    }

    public function test_a_vm_moved_to_another_node_can_be_accepted()
    {
        $pve2 = $this->addNode('pve2');
        $this->proxmox->vms[$this->server->vmid]['node'] = 'pve2';

        $this->detect();
        $drift = $this->drift(DriftType::NodeMismatch);
        $this->assertSame(['node' => 'pve2'], $drift->actual);

        $this->actingAs($this->admin)->post(route('admin.drifts.accept', $drift));

        $this->assertSame($pve2->id, $this->server->refresh()->node_id);
        $this->assertNotNull($drift->refresh()->resolved_at);
    }

    public function test_resources_changed_in_proxmox_can_be_accepted()
    {
        $this->proxmox->vms[$this->server->vmid]['config']['cores'] = 4;
        $this->proxmox->vms[$this->server->vmid]['config']['memory'] = 8192;

        $this->detect();
        $drift = $this->drift(DriftType::ResourceMismatch);
        $this->assertSame(['cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 40960], $drift->actual);

        $this->actingAs($this->admin)->post(route('admin.drifts.accept', $drift));

        $server = $this->server->refresh();
        $this->assertSame(4, $server->cpu_cores);
        $this->assertSame(8192, $server->memory_mib);
    }

    public function test_a_running_vm_of_a_suspended_server_is_reported_and_stopped_again()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->detect();
        $drift = ServerDrift::query()->visible()->sole();
        $this->assertSame(DriftType::PowerMismatch, $drift->type);

        $this->actingAs($this->admin)->post(route('admin.drifts.apply-power', $drift));
        $this->assertSame('stopped', $this->vm()['status']);

        $this->detect();
        $this->assertSame('gone', $drift->refresh()->resolution, 'The next sync closes it.');
    }

    public function test_a_stopped_vm_of_an_active_server_is_only_shown_after_the_grace_period()
    {
        $this->proxmox->vms[$this->server->vmid]['status'] = 'stopped';

        $this->detect();
        $this->assertSame(1, ServerDrift::query()->count());
        $this->assertSame(0, ServerDrift::query()->visible()->count());

        $this->travel(61)->minutes();
        $this->detect();

        $this->assertSame(1, ServerDrift::query()->visible()->count());
        $this->assertSame(1, ServerDrift::query()->count(), 'The same drift is updated, not recorded again.');
    }

    public function test_vms_in_the_pool_that_the_panel_does_not_know_are_reported()
    {
        $this->proxmox->addVm(500, 'stray.example.com');
        $this->proxmox->addVm(501, 'outside.example.com', pool: null);

        $this->detect();

        $drift = $this->drift(DriftType::UnmanagedVm);
        $this->assertSame(500, $drift->vmid);
        $this->assertNull($drift->server_id);
        $this->assertSame('stray.example.com', $drift->actual['name'] ?? null);
    }

    public function test_drifts_close_by_themselves_once_proxmox_matches_again()
    {
        $this->proxmox->vms[$this->server->vmid]['config']['cores'] = 4;
        $this->detect();
        $drift = $this->drift(DriftType::ResourceMismatch);

        $this->proxmox->vms[$this->server->vmid]['config']['cores'] = 2;
        $this->detect();

        $this->assertSame('gone', $drift->refresh()->resolution);
    }

    public function test_servers_with_a_running_task_are_left_alone()
    {
        $this->proxmox->vms[$this->server->vmid]['config']['cores'] = 4;
        $this->detect();
        $drift = $this->drift(DriftType::ResourceMismatch);

        $task = new Task;
        $task->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();
        unset($this->proxmox->vms[$this->server->vmid]);
        $this->detect();

        $this->assertNull($drift->refresh()->resolved_at, 'Not closed while the server was skipped.');
        $this->assertFalse(ServerDrift::query()->where('type', DriftType::MissingVm)->exists());
    }

    public function test_dismissed_drifts_stay_hidden_while_they_last()
    {
        $this->proxmox->vms[$this->server->vmid]['config']['cores'] = 4;
        $this->detect();
        $drift = $this->drift(DriftType::ResourceMismatch);

        $this->actingAs($this->admin)->post(route('admin.drifts.dismiss', $drift));
        $this->detect();

        $this->assertSame(1, ServerDrift::query()->count());
        $this->assertSame(0, ServerDrift::query()->visible()->count());
    }

    public function test_admins_see_the_report_on_the_dashboard_the_list_and_the_server_page()
    {
        unset($this->proxmox->vms[$this->server->vmid]);
        $this->detect();
        $this->actingAs($this->admin);

        $this->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page->where('stats.drifts', 1));
        $this->get(route('admin.drifts.index'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/drifts/Index')
            ->where('drifts.data.0.type', 'missing_vm')
            ->where('drifts.data.0.server.uuid', $this->server->uuid));
        $this->get(route('admin.servers.show', $this->server))->assertInertia(fn (Assert $page) => $page
            ->where('drifts.0.type', 'missing_vm'));
    }

    public function test_customers_cannot_see_or_act_on_drifts()
    {
        unset($this->proxmox->vms[$this->server->vmid]);
        $this->detect();
        $drift = $this->drift(DriftType::MissingVm);
        $this->actingAs($this->customer);

        $this->get(route('admin.drifts.index'))->assertForbidden();
        $this->post(route('admin.drifts.mark-error', $drift))->assertForbidden();
        $this->post(route('admin.drifts.dismiss', $drift))->assertForbidden();

        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $this->assertNull($drift->refresh()->dismissed_at);
    }
}
