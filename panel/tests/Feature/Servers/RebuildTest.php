<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskPlan;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Rebuild from scratch (docs/adr/0032 §7): a failed creation's half-built VM
 * is deleted and the server created again with the same VMID, addresses and
 * MAC address, where Retry would keep using the broken VM.
 */
class RebuildTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->withTwoFactor()->create();
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();

        // Proxmox created the VM before the clone failed: a broken VM is left behind.
        $this->proxmox->failClone = 'clone failed: storage full';
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->proxmox->failClone = null;
    }

    private function rebuild(?string $hostname = null): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.rebuild', $this->server), ['confirm_hostname' => $hostname ?? $this->server->hostname]);
    }

    public function test_a_failed_creation_is_rebuilt_without_its_broken_vm()
    {
        $this->assertSame(ServerStatus::Error, $this->server->status);
        $vm = &$this->proxmox->vms[$this->server->vmid];
        $vm['config']['net0'] = (string) preg_replace('/=[0-9A-F:]{17}/i', '=BC:24:11:AA:BB:CC', (string) $vm['config']['net0']);
        $vm['status'] = 'running';
        unset($vm);
        $address = $this->server->ipAddresses()->sole()->address;

        $this->rebuild()->assertRedirect(route('admin.servers.show', $this->server));

        $task = $this->server->tasks()->latest('id')->first();
        $this->assertSame(TaskType::Create, $task->type);
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['stop', 'destroy', 'clone'], array_slice(array_column($task->steps, 'key'), 0, 3));
        $this->assertNull(collect($task->steps)->firstWhere('key', 'clone')['message'], 'Cloned again, not "VM already exists."');

        $writes = $this->proxmox->writePaths();
        $this->assertContains("DELETE /nodes/pve1/qemu/{$this->server->vmid}", $writes);
        $this->assertCount(2, array_filter($writes, fn (string $write) => str_ends_with($write, '/clone')));

        $server = $this->server->refresh();
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame($address, $server->ipAddresses()->sole()->address);
        $this->assertStringContainsString('BC:24:11:AA:BB:CC', (string) $this->proxmox->vms[$server->vmid]['config']['net0']);

        $log = AuditLog::query()->where('action', 'server.rebuilt')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['hostname' => 'web1.example.com', 'vmid' => $server->vmid], $log->properties);
    }

    public function test_a_vm_the_panel_did_not_create_for_the_server_is_never_deleted()
    {
        $vm = &$this->proxmox->vms[$this->server->vmid];
        $vm['config']['name'] = 'someone-elses-vm';
        $vm['config']['description'] = 'Managed by hand.';
        unset($vm);

        $this->rebuild();

        $task = $this->server->tasks()->latest('id')->first();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertStringStartsWith("VM {$this->server->vmid} was not created by Proxmotion for this server", (string) $task->error);
        $this->assertNotContains("DELETE /nodes/pve1/qemu/{$this->server->vmid}", $this->proxmox->writePaths());
        $this->assertSame('someone-elses-vm', $this->proxmox->vms[$this->server->vmid]['config']['name']);
        $this->assertSame(ServerStatus::Error, $this->server->refresh()->status);
    }

    public function test_the_hostname_must_be_typed_to_confirm()
    {
        $this->rebuild('wrong.example.com')->assertSessionHasErrors('confirm_hostname');

        $this->assertSame(1, $this->server->tasks()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'server.rebuilt')->count());
    }

    public function test_only_a_failed_creation_can_be_rebuilt()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('server.can_rebuild', true));

        // Retry still continues with the VM that is there.
        app(TaskRunner::class)->start($this->server, TaskType::Create);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);

        $this->rebuild()->assertForbidden();
        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('server.can_rebuild', false));

        // A failed reinstall is retried, not rebuilt.
        app(TaskRunner::class)->start($this->server, TaskType::Reinstall);
        $this->server->tasks()->latest('id')->first()->forceFill(['status' => TaskStatus::Failed])->save();
        $this->server->forceFill(['status' => ServerStatus::Error])->save();

        $this->rebuild()->assertForbidden();
    }

    public function test_the_plan_of_a_rebuild_starts_with_the_deletion()
    {
        $this->rebuild();
        $task = $this->server->tasks()->latest('id')->first();

        $this->assertSame(['stop', 'destroy', 'clone'], array_slice(app(TaskPlan::class)->steps($task), 0, 3));
        $this->assertSame(['fresh' => true], array_intersect_key($task->input ?? [], ['fresh' => true]));
    }
}
