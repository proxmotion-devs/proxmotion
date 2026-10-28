<?php

namespace Tests\Feature\Servers;

use App\Domain\Apps\AppTrials;
use App\Domain\Backups\ServerSnapshots;
use App\Domain\Backups\SnapshotSlotsFull;
use App\Domain\Backups\SnapshotsUnavailable;
use App\Domain\Servers\ServerPower;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppInstallationStatus;
use App\Enums\PowerAction;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Snapshots of a server (docs/adr/0051): taken, rolled back with the
 * panel's state written back afterwards, and deleted.
 */
class SnapshotTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->package->forceFill(['snapshot_slots' => 2, 'backup_slots' => 3])->save();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function snapshots(): ServerSnapshots
    {
        return app(ServerSnapshots::class);
    }

    private function take(?string $label = 'before upgrade'): Task
    {
        $task = $this->snapshots()->take($this->server, $label, $this->admin, '127.0.0.1')->refresh();
        $this->server->refresh();

        return $task;
    }

    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    public function test_the_package_slots_are_copied_to_the_server()
    {
        $this->assertSame(2, $this->server->snapshot_slots);
        $this->assertSame(3, $this->server->backup_slots);
    }

    public function test_a_snapshot_is_taken_without_ram_and_listed()
    {
        $task = $this->take();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $snapshot = Snapshot::query()->sole();
        $this->assertSame('before upgrade', $snapshot->label);
        $this->assertMatchesRegularExpression('/^pmx[a-z0-9]+$/', $snapshot->name);
        $this->assertArrayHasKey($snapshot->name, $this->proxmox->snapshots[$this->server->vmid]);
        $this->assertSame('before upgrade', $this->proxmox->snapshots[$this->server->vmid][$snapshot->name]['description']);

        $write = collect($this->proxmox->writes)->first(fn (array $write) => str_ends_with($write['path'], '/snapshot'));
        $this->assertSame('0', (string) $write['data']['vmstate'], 'Never the RAM.');

        $this->assertSame([['id' => $snapshot->uuid, 'label' => 'before upgrade', 'created_at' => $snapshot->created_at?->toIso8601String()]], ServerSnapshots::describe($this->server));
        $this->assertTrue(AuditLog::query()->where('action', 'server.snapshot_requested')->exists());
    }

    public function test_a_server_takes_no_more_snapshots_than_its_slots()
    {
        $this->take('one');
        $this->take('two');

        $this->expectException(SnapshotSlotsFull::class);
        $this->take('three');
    }

    public function test_a_server_without_slots_or_on_storage_without_snapshots_takes_none()
    {
        $this->server->forceFill(['snapshot_slots' => 0])->save();
        $this->assertSame('Snapshots are not part of this server\'s plan.', ServerSnapshots::unavailable($this->server)?->getMessage());

        $this->server->forceFill(['snapshot_slots' => 2])->save();
        $this->server->storage?->forceFill(['type' => 'lvm'])->save();
        $this->assertSame('The storage of this server (lvm) does not support snapshots.', ServerSnapshots::unavailable($this->server->refresh())?->translatedMessage());

        $this->expectException(SnapshotsUnavailable::class);
        $this->take();
    }

    public function test_a_failed_snapshot_is_not_listed()
    {
        $this->proxmox->failSnapshot = 'snapshot feature is not available';

        $task = $this->take();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_a_rollback_brings_back_the_disk_and_writes_the_panels_state_again()
    {
        $this->take();
        $snapshot = Snapshot::query()->sole();
        $app = AppInstallation::factory()->for($this->server)->create(['status' => AppInstallationStatus::Installed, 'created_at' => now()->addMinute()]);
        $this->server->forceFill(['root_password' => 'Secret-password-1', 'root_password_unrevealed' => true])->save();

        // After the snapshot the server got more CPU, and someone changed the VM by hand.
        $this->server->forceFill(['cpu_cores' => 4])->save();
        $this->proxmox->vms[$this->server->vmid]['ipset'] = [...($this->vm()['ipset'] ?? []), '198.51.100.99'];

        $this->travel(2)->minutes();
        $task = $this->snapshots()->rollback($this->server, $snapshot, $this->admin, '127.0.0.1')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(4, (int) $this->vm()['config']['cores'], 'The panel\'s resources, not the snapshot\'s.');
        $this->assertNotContains('198.51.100.99', $this->vm()['ipset'], 'Exactly the server\'s addresses.');
        $this->assertSame('running', $this->vm()['status'], 'Started again: it was running.');
        $this->assertSame(['rollback', 'configure', 'resize', 'firewall', 'start', 'guest_addresses', 'guest_hostname', 'guest_ssh_keys'], array_values(array_intersect(
            array_column($task->steps, 'key'),
            ['rollback', 'configure', 'resize', 'firewall', 'start', 'guest_addresses', 'guest_hostname', 'guest_ssh_keys'],
        )));

        $server = $this->server->refresh();
        $this->assertNull($server->root_password, 'The guest has the password of the snapshot\'s time.');
        $this->assertFalse($server->root_password_unrevealed);
        $this->assertSame(AppInstallationStatus::Removed, $app->refresh()->status, 'Installed after the snapshot: gone.');
        $this->assertTrue(Snapshot::query()->exists(), 'The snapshot stays.');
        $this->assertTrue(AuditLog::query()->where('action', 'server.snapshot_rolled_back')->exists());
    }

    public function test_no_rollback_while_an_app_script_runs()
    {
        $this->take();
        $installing = AppInstallation::factory()->for($this->server)->create(['status' => AppInstallationStatus::Installing]);

        try {
            $this->snapshots()->rollback($this->server, Snapshot::query()->sole(), $this->admin, null);
            $this->fail('The rollback was queued.');
        } catch (ProvisioningException $e) {
            $this->assertSame('An app script is running on this server: wait until it has finished, or cancel it.', $e->getMessage());
        }

        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::RollbackSnapshot)->count());

        $installing->forceFill(['status' => AppInstallationStatus::Installed])->save();
        $this->assertSame(TaskStatus::Succeeded, $this->snapshots()->rollback($this->server, Snapshot::query()->sole(), $this->admin, null)->refresh()->status);
    }

    public function test_a_stopped_server_stays_stopped_after_a_rollback()
    {
        $this->take();
        $this->proxmox->vms[$this->server->vmid]['status'] = 'stopped';

        $task = $this->snapshots()->rollback($this->server, Snapshot::query()->sole(), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('stopped', $this->vm()['status']);
        $this->assertNotContains('guest_addresses', array_column($task->steps, 'key'));
        $this->assertTrue($this->server->refresh()->guest_refresh_pending, 'Its addresses, hostname and keys wait for the next start.');

        // The next start through the panel writes them into the guest.
        $start = app(ServerPower::class)->request($this->server, PowerAction::Start, $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $start->status, (string) $start->error);
        $this->assertSame('running', $this->vm()['status']);
        $this->assertContains('guest_addresses', array_column($start->steps, 'key'));
        $this->assertContains('guest_ssh_keys', array_column($start->steps, 'key'));
        $this->assertFalse($this->server->refresh()->guest_refresh_pending);

        // Later starts do nothing more.
        app(ServerPower::class)->request($this->server, PowerAction::Reboot, $this->admin, null);
        $this->assertNotContains('guest_addresses', array_column($this->server->tasks()->latest('id')->firstOrFail()->steps, 'key'));
    }

    public function test_a_snapshot_is_deleted_also_when_proxmox_no_longer_has_it()
    {
        $this->take('one');
        $this->take('two');
        [$first, $second] = Snapshot::query()->orderBy('id')->get()->all();

        $task = $this->snapshots()->delete($this->server, $first, $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertArrayNotHasKey($first->name, $this->proxmox->snapshots[$this->server->vmid]);

        unset($this->proxmox->snapshots[$this->server->vmid][$second->name]);
        $task = $this->snapshots()->delete($this->server, $second, $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_the_disk_cannot_grow_while_there_are_snapshots()
    {
        $this->take();
        $bigger = new ServerSpec(cpuCores: 2, memoryMib: 4096, diskMib: 81920, ipv4Count: 1, ipv6Count: 0, portSpeedMbps: 1000);

        try {
            app(ResourceChanger::class)->request($this->server, new ResourceChange(spec: $bigger), $this->admin, null);
            $this->fail('The disk grew.');
        } catch (ProvisioningException $e) {
            $this->assertSame('The disk cannot grow while the server has snapshots. Delete them first.', $e->getMessage());
        }

        // CPU and memory may still change.
        $task = app(ResourceChanger::class)->request($this->server, new ResourceChange(spec: new ServerSpec(cpuCores: 4, memoryMib: 8192, diskMib: 40960, ipv4Count: 1, ipv6Count: 0, portSpeedMbps: 1000)), $this->admin, null);
        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status, (string) $task->error);
    }

    public function test_a_reinstall_takes_the_snapshots_along()
    {
        $this->take();

        $task = app(ServerReinstaller::class)->request($this->server, $this->osTemplate, [], $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_administrators_take_roll_back_and_delete_snapshots_on_the_server_page()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.servers.snapshots.store', $this->server), ['label' => 'Before the upgrade'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $snapshot = Snapshot::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('snapshots.slots', 2)
                ->where('snapshots.unavailable', null)
                ->where('snapshots.items.0.label', 'Before the upgrade'));

        $this->actingAs($this->admin)
            ->post(route('admin.servers.snapshots.rollback', [$this->server, $snapshot]), ['confirm_hostname' => 'wrong.example.com'])
            ->assertSessionHasErrors('confirm_hostname');
        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::RollbackSnapshot)->count());

        $this->actingAs($this->admin)
            ->post(route('admin.servers.snapshots.rollback', [$this->server, $snapshot]), ['confirm_hostname' => strtoupper($this->server->hostname)])
            ->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::Succeeded, $this->server->tasks()->where('type', TaskType::RollbackSnapshot)->sole()->status);

        $this->actingAs($this->admin)
            ->delete(route('admin.servers.snapshots.destroy', [$this->server, $snapshot]))
            ->assertRedirect();
        $this->assertSame(0, Snapshot::query()->count());
    }

    public function test_a_test_server_is_reset_to_its_trial_base_snapshot()
    {
        $this->server->forceFill(['is_test' => true])->save();
        $this->take('Trial base');

        $task = app(AppTrials::class)->resetTestServer($this->server->refresh(), $this->admin, null)->refresh();

        $this->assertSame(TaskType::RollbackSnapshot, $task->type);
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
    }
}
