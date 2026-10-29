<?php

namespace Tests\Feature\Servers;

use App\Domain\Backups\BackupNotRestorable;
use App\Domain\Backups\BackupSlotsFull;
use App\Domain\Backups\BackupsUnavailable;
use App\Domain\Backups\ServerBackups;
use App\Domain\Backups\ServerSnapshots;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppInstallationStatus;
use App\Enums\BackupKind;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Jobs\StopProxmoxTask;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Storage;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Pipelines\CreateBackupPipeline;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Backups of a server (docs/adr/0051 §4): taken with vzdump to a storage
 * marked for backups, restored with the panel's state written back
 * afterwards, and deleted.
 */
class BackupTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    private Storage $backupStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->package->forceFill(['snapshot_slots' => 1, 'backup_slots' => 2])->save();
        $this->backupStorage = $this->addBackupStorage('backup-nfs', shared: true, freeGib: 800);
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function addBackupStorage(string $name, bool $shared, int $freeGib, string $type = 'nfs', bool $use = true): Storage
    {
        return $this->node->storages()->forceCreate([
            'name' => $name,
            'type' => $type,
            'content' => ['backup', 'iso'],
            'is_shared' => $shared,
            'is_available' => true,
            'use_for_servers' => false,
            'use_for_backups' => $use,
            'total_bytes' => 1000 * 1024 ** 3,
            'used_bytes' => (1000 - $freeGib) * 1024 ** 3,
        ]);
    }

    private function backups(): ServerBackups
    {
        return app(ServerBackups::class);
    }

    private function take(?string $label = 'before upgrade'): Task
    {
        $task = $this->backups()->take($this->server, $label, $this->admin, '127.0.0.1')->refresh();
        $this->server->refresh();

        return $task;
    }

    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    public function test_a_backup_is_taken_in_snapshot_mode_and_listed()
    {
        $task = $this->take();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['backup', 'record_backup'], array_column($task->steps, 'key'));

        $backup = Backup::query()->sole();
        $this->assertSame('before upgrade', $backup->label);
        $this->assertSame(BackupKind::Manual, $backup->kind);
        $this->assertSame('backup-nfs', $backup->storage);
        $this->assertSame($this->proxmox->backupVolumes['backup-nfs'][0]['volid'], $backup->volume);
        $this->assertSame(1024 ** 3, $backup->size_bytes);
        $this->assertSame($this->server->os_template_id, $backup->os_template_id);
        $this->assertSame($this->server->hostname, $backup->hostname);
        $this->assertSame($this->node->id, $backup->node_id);

        $vzdump = $this->proxmox->vzdumps[0];
        $this->assertSame('snapshot', $vzdump['mode'], 'The server keeps running.');
        $this->assertSame('zstd', $vzdump['compress']);
        $this->assertSame('0', (string) $vzdump['remove'], 'The panel prunes, not Proxmox.');
        $this->assertSame(CreateBackupPipeline::notes($this->server, $backup->uuid), $vzdump['notes-template']);

        $this->assertSame([[
            'id' => $backup->uuid,
            'kind' => 'manual',
            'label' => 'before upgrade',
            'size_bytes' => 1024 ** 3,
            'os_template' => $this->osTemplate->name,
            'created_at' => $backup->created_at?->toIso8601String(),
            'restorable' => true,
            'unrestorable_reason' => null,
        ]], ServerBackups::describe($this->server));
        $this->assertTrue(AuditLog::query()->where('action', 'server.backup_requested')->exists());
    }

    public function test_the_backup_goes_to_the_reachable_storage_with_the_most_room()
    {
        $this->addBackupStorage('pbs', shared: true, freeGib: 900, type: 'pbs');
        $this->addBackupStorage('roomy-but-off', shared: true, freeGib: 990, use: false);
        $this->node->storages()->where('name', 'local-lvm')->update(['use_for_backups' => true]);

        $this->assertSame('pbs', ServerBackups::storageFor($this->server)?->name, 'Only storages taking backups count.');

        $task = $this->take();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('pbs', $this->proxmox->vzdumps[0]['storage']);
        $this->assertArrayNotHasKey('compress', $this->proxmox->vzdumps[0], 'Proxmox Backup Server compresses on its own.');
    }

    public function test_a_server_takes_no_more_backups_than_its_slots()
    {
        $this->take('one');
        $this->take('two');

        $this->expectException(BackupSlotsFull::class);
        $this->take('three');
    }

    public function test_a_server_without_slots_or_backup_storage_takes_none()
    {
        $this->server->forceFill(['backup_slots' => 0])->save();
        $this->assertSame('backups_not_supported', ServerBackups::unavailable($this->server)?->error);

        $this->server->forceFill(['backup_slots' => 2])->save();
        $this->backupStorage->forceFill(['use_for_backups' => false])->save();
        $this->assertSame('No storage that this server\'s node reaches is marked for backups.', ServerBackups::unavailable($this->server)?->translatedMessage());

        try {
            $this->take();
            $this->fail('The backup was queued.');
        } catch (BackupsUnavailable $e) {
            $this->assertSame('no_backup_storage', $e->error);
        }

        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::CreateBackup)->count());
    }

    public function test_a_failed_backup_is_not_listed()
    {
        $this->proxmox->failBackup = 'job errors';

        $task = $this->take();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_a_restore_brings_back_the_backup_and_writes_the_panels_state_again()
    {
        $this->take();
        $backup = Backup::query()->sole();
        $app = AppInstallation::factory()->for($this->server)->create(['status' => AppInstallationStatus::Installed, 'created_at' => now()->addMinute()]);
        app(ServerSnapshots::class)->take($this->server, 'later', $this->admin, null);
        $this->assertSame(1, Snapshot::query()->count());

        // After the backup the server got more CPU, another system and a new password, and someone changed the VM by hand.
        $other = OsTemplate::factory()->create();
        $this->server->forceFill(['cpu_cores' => 4, 'os_template_id' => $other->id, 'root_password' => 'Secret-password-1', 'root_password_unrevealed' => true])->save();
        $this->proxmox->vms[$this->server->vmid]['ipset'] = [...($this->vm()['ipset'] ?? []), '198.51.100.99'];
        $this->proxmox->backupVolumes['backup-nfs'][0]['vm']['ipset'] = [...($this->vm()['ipset'] ?? [])];

        $this->travel(2)->minutes();
        $task = $this->backups()->restore($this->server->refresh(), $backup, $this->admin, '127.0.0.1')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $restore = collect($this->proxmox->writes)->first(fn (array $write) => $write['path'] === '/nodes/pve1/qemu');
        $this->assertSame($backup->volume, $restore['data']['archive']);
        $this->assertSame('1', (string) $restore['data']['force']);
        $this->assertSame('0', (string) $restore['data']['unique'], 'The MAC address stays.');
        $this->assertSame('local-lvm', $restore['data']['storage']);

        $this->assertSame(4, (int) $this->vm()['config']['cores'], 'The panel\'s resources, not the backup\'s.');
        $this->assertNotContains('198.51.100.99', $this->vm()['ipset'], 'Exactly the server\'s addresses.');
        $this->assertStringContainsString("Server {$this->server->uuid}", (string) $this->vm()['config']['description']);
        $this->assertSame('running', $this->vm()['status'], 'Started again: it was running.');
        $steps = ['stop', 'restore', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'guest_addresses', 'guest_hostname', 'guest_ssh_keys'];
        $this->assertSame($steps, array_values(array_intersect(array_column($task->steps, 'key'), $steps)));

        $server = $this->server->refresh();
        $this->assertSame($this->osTemplate->id, $server->os_template_id, 'The backup\'s system.');
        $this->assertNull($server->root_password, 'The guest has the password of the backup\'s time.');
        $this->assertFalse($server->root_password_unrevealed);
        $this->assertFalse($server->guest_refresh_pending);
        $this->assertSame(AppInstallationStatus::Removed, $app->refresh()->status, 'Installed after the backup: gone.');
        $this->assertSame(0, Snapshot::query()->count(), 'The restore replaced the disks and their snapshots.');
        $this->assertTrue(Backup::query()->exists(), 'The backup stays.');
        $this->assertTrue(AuditLog::query()->where('action', 'server.backup_restore_requested')->exists());
    }

    public function test_a_stopped_server_stays_stopped_after_a_restore()
    {
        $this->take();
        $this->proxmox->vms[$this->server->vmid]['status'] = 'stopped';

        $task = $this->backups()->restore($this->server, Backup::query()->sole(), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('stopped', $this->vm()['status']);
        $this->assertNotContains('guest_addresses', array_column($task->steps, 'key'));
        $this->assertTrue($this->server->refresh()->guest_refresh_pending, 'Its addresses, hostname and keys wait for the next start.');
    }

    public function test_no_restore_while_an_app_script_runs()
    {
        $this->take();
        AppInstallation::factory()->for($this->server)->create(['status' => AppInstallationStatus::Installing]);

        $this->expectExceptionMessage('An app script is running on this server: wait until it has finished, or cancel it.');
        $this->backups()->restore($this->server, Backup::query()->sole(), $this->admin, null);
    }

    public function test_a_backup_the_servers_node_cannot_reach_is_not_restorable()
    {
        $this->backupStorage->forceFill(['is_shared' => false])->save();
        $this->take();
        $backup = Backup::query()->sole();

        // The server moved to another node, which has a storage of the same name of its own.
        $other = $this->addNode('pve2');
        $other->storages()->forceCreate([...$this->backupStorage->only(['name', 'type', 'content', 'is_available', 'use_for_backups']), 'is_shared' => false]);
        $this->server->forceFill(['node_id' => $other->id])->save();

        $this->assertSame('The server\'s node cannot reach the storage of this backup.', ServerBackups::describe($this->server->refresh())[0]['unrestorable_reason']);
        $this->assertFalse(ServerBackups::describe($this->server)[0]['restorable']);

        try {
            $this->backups()->restore($this->server, $backup, $this->admin, null);
            $this->fail('The restore was queued.');
        } catch (BackupNotRestorable $e) {
            $this->assertSame('The server\'s node cannot reach the storage of this backup.', $e->getMessage());
        }
    }

    public function test_a_backup_is_deleted_also_when_proxmox_no_longer_has_it()
    {
        $this->take('one');
        $this->take('two');
        [$first, $second] = Backup::query()->orderBy('id')->get()->all();

        $task = $this->backups()->delete($this->server, $first, $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame([$second->volume], array_column($this->proxmox->backupVolumes['backup-nfs'], 'volid'));

        $this->proxmox->backupVolumes['backup-nfs'] = [];
        $task = $this->backups()->delete($this->server, $second, $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(0, Backup::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'server.backup_deleted')->exists());
    }

    public function test_backups_survive_a_reinstall_and_go_with_a_termination()
    {
        $this->take();

        $reinstall = app(ServerReinstaller::class)->request($this->server, $this->osTemplate, [], $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $reinstall->status, (string) $reinstall->error);
        $this->assertSame(1, Backup::query()->count());

        // A backup taken before the reinstall can be restored after it.
        $restore = $this->backups()->restore($this->server->refresh(), Backup::query()->sole(), $this->admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $restore->status, (string) $restore->error);

        $terminate = app(TaskRunner::class)->start($this->server->refresh(), TaskType::Terminate, $this->admin)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $terminate->status, (string) $terminate->error);
        $this->assertContains('delete_backups', array_column($terminate->steps, 'key'));
        $this->assertSame(0, Backup::query()->count());
        $this->assertSame([], $this->proxmox->backupVolumes['backup-nfs']);
    }

    public function test_an_administrator_cancels_a_running_backup()
    {
        Queue::fake([RunServerTask::class, StopProxmoxTask::class]);
        $this->proxmox->runningPolls['vzdump'] = 5;
        $task = $this->backups()->take($this->server, null, $this->admin, null);

        $job = Queue::pushed(RunServerTask::class, fn (RunServerTask $job) => $job->task->is($task))->sole();
        $job = unserialize(serialize($job));
        $job->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $task->refresh();
        $this->assertSame(TaskStatus::Running, $task->status);
        $upid = $task->stageValue('upid');
        $this->assertIsString($upid);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.can_cancel', true));

        $this->actingAs($this->admin)
            ->post(route('admin.tasks.cancel', $task))
            ->assertRedirect();

        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
        $stop = Queue::pushed(StopProxmoxTask::class)->sole();
        $this->assertSame($upid, $stop->upid);
        app()->call([$stop, 'handle']);
        $this->assertSame([$upid], $this->proxmox->stoppedTasks);
    }

    public function test_administrators_take_restore_and_delete_backups_on_the_server_page()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.servers.backups.store', $this->server), ['label' => 'Before the upgrade'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $backup = Backup::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('backups.slots', 2)
                ->where('backups.unavailable', null)
                ->where('backups.storage', 'backup-nfs')
                ->where('backups.items.0.label', 'Before the upgrade')
                ->where('backups.items.0.storage', 'backup-nfs'));

        $this->actingAs($this->admin)
            ->post(route('admin.servers.backups.restore', [$this->server, $backup]), ['confirm_hostname' => 'wrong.example.com'])
            ->assertSessionHasErrors('confirm_hostname');
        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::RestoreBackup)->count());

        $this->actingAs($this->admin)
            ->post(route('admin.servers.backups.restore', [$this->server, $backup]), ['confirm_hostname' => strtoupper($this->server->hostname)])
            ->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::Succeeded, $this->server->tasks()->where('type', TaskType::RestoreBackup)->sole()->status);

        $this->actingAs($this->admin)
            ->delete(route('admin.servers.backups.destroy', [$this->server, $backup]))
            ->assertRedirect();
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_storages_are_marked_for_backups_only_when_they_take_backups()
    {
        $this->actingAs($this->admin)
            ->patch(route('admin.storages.update', $this->storage), ['use_for_backups' => true])
            ->assertSessionHasErrors('use_for_backups');
        $this->assertFalse($this->storage->refresh()->use_for_backups);
        $this->assertTrue($this->storage->use_for_servers, 'The other flag stays.');

        $this->actingAs($this->admin)
            ->patch(route('admin.storages.update', $this->backupStorage), ['use_for_backups' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->backupStorage->refresh()->use_for_backups);
    }

    public function test_backups_of_another_server_are_not_found_on_this_page()
    {
        $other = app(ServerCreator::class)->create($this->order())->refresh();
        $this->backups()->take($other, null, $this->admin, null);
        $foreign = Backup::query()->where('server_id', $other->id)->sole();

        $this->actingAs($this->admin)
            ->delete(route('admin.servers.backups.destroy', [$this->server, $foreign]))
            ->assertNotFound();
    }

    public function test_it_is_refused_while_the_server_is_not_active()
    {
        $this->server->forceFill(['status' => 'suspended'])->save();

        $this->expectException(ProvisioningException::class);
        $this->take();
    }
}
