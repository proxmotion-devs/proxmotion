<?php

namespace Tests\Feature\Servers;

use App\Domain\Backups\BackupSchedules;
use App\Domain\Backups\ServerBackups;
use App\Enums\BackupKind;
use App\Enums\BackupSchedule;
use App\Enums\NotificationEvent;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Scheduled backups and retention (docs/adr/0051 §6): queued once per
 * backup window for servers whose package or own schedule asks for them,
 * within the limits per node and storage; the oldest scheduled backups
 * beyond the slots are deleted, manual ones never.
 */
class BackupScheduleTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'proxmotion.backups.window_start' => '01:00',
            'proxmotion.backups.window_end' => '06:00',
            'proxmotion.backups.per_node' => 1,
            'proxmotion.backups.per_storage' => 2,
            'proxmotion.backups.keep_terminated_days' => 0,
        ]);

        $this->buildInfrastructure();
        $this->package->forceFill(['backup_slots' => 2, 'backup_schedule' => BackupSchedule::Daily])->save();
        $this->addBackupStorage('backup-nfs');
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function addBackupStorage(string $name): Storage
    {
        return $this->node->storages()->forceCreate([
            'name' => $name,
            'type' => 'nfs',
            'content' => ['backup'],
            'is_shared' => true,
            'is_available' => true,
            'use_for_servers' => false,
            'use_for_backups' => true,
            'total_bytes' => 1000 * 1024 ** 3,
            'used_bytes' => 200 * 1024 ** 3,
        ]);
    }

    private function schedules(): BackupSchedules
    {
        return app(BackupSchedules::class);
    }

    /**
     * Run the schedule on the given day, late enough in the window for every server to be due.
     */
    private function runOn(string $day, string $time = '03:40'): int
    {
        $this->travelTo(CarbonImmutable::parse("{$day} {$time}", 'UTC'));

        return $this->schedules()->queueDue(now());
    }

    private function backupTasks(): int
    {
        return Task::query()->where('type', TaskType::CreateBackup)->count();
    }

    public function test_a_due_server_is_backed_up_once_per_window()
    {
        $this->assertSame(1, $this->runOn('2026-11-03'));

        $backup = Backup::query()->sole();
        $this->assertSame(BackupKind::Scheduled, $backup->kind);
        $this->assertNull($backup->label);
        $task = Task::query()->where('type', TaskType::CreateBackup)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNull($task->user_id, 'Nobody started it.');
        $this->assertSame(['backup', 'record_backup', 'prune_backups'], array_column($task->steps, 'key'));
        $this->assertTrue($this->server->refresh()->backup_scheduled_at?->equalTo(now()));

        // Not again in the same window, but in the next one.
        $this->assertSame(0, $this->runOn('2026-11-03', '05:50'));
        $this->assertSame(1, $this->runOn('2026-11-04'));
        $this->assertSame(2, Backup::query()->count());
    }

    public function test_nothing_is_queued_outside_the_window_or_before_the_servers_turn()
    {
        $this->assertSame(0, $this->runOn('2026-11-03', '00:50'));
        $this->assertSame(0, $this->runOn('2026-11-03', '06:00'));

        // Spread over the window's first half by id, ten minutes apart.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 00:30', 'UTC'));
        $due = BackupSchedules::nextAt($this->server, now());
        $this->assertNotNull($due);
        $this->assertSame('2026-11-03 01:00', $due->copy()->subSeconds(($this->server->id * 600) % 9000)->format('Y-m-d H:i'));

        $this->travelTo($due->subMinute());
        $this->assertSame(0, $this->schedules()->queueDue(now()));
        $this->travelTo($due);
        $this->assertSame(1, $this->schedules()->queueDue(now()));
        $this->assertSame(0, $this->runOn('2026-11-03', '06:30'));
    }

    public function test_a_window_may_end_on_the_next_day()
    {
        config(['proxmotion.backups.window_start' => '22:00', 'proxmotion.backups.window_end' => '04:00']);

        $this->assertSame(0, $this->runOn('2026-11-03', '21:59'));
        $this->assertSame(1, $this->runOn('2026-11-04', '03:30'));
        // Still the window that began yesterday.
        $this->assertSame(0, $this->runOn('2026-11-04', '03:50'));
        $this->assertSame(['2026-11-03 22:00', '2026-11-04 04:00'], array_map(
            fn (CarbonImmutable $time) => $time->format('Y-m-d H:i'),
            BackupSchedules::windowAt(CarbonImmutable::parse('2026-11-04 03:50', 'UTC')) ?? [],
        ));
    }

    public function test_weekly_servers_are_backed_up_on_their_weekday()
    {
        $this->package->forceFill(['backup_schedule' => BackupSchedule::Weekly])->save();
        $monday = CarbonImmutable::parse('2026-11-02', 'UTC');
        $ownDay = $monday->addDays($this->server->id % 7);

        $this->assertSame(0, $this->runOn($ownDay->addDay()->toDateString()));
        $this->assertSame(1, $this->runOn($ownDay->toDateString()));
        $this->assertSame(0, $this->runOn($ownDay->addDays(6)->toDateString()));
        $this->assertSame(1, $this->runOn($ownDay->addDays(7)->toDateString()));
    }

    public function test_the_servers_own_schedule_wins_over_its_packages()
    {
        $this->server->forceFill(['backup_schedule' => BackupSchedule::None])->save();
        $this->assertSame(0, $this->runOn('2026-11-03'));
        $this->assertSame(BackupSchedule::None, BackupSchedules::of($this->server));

        $this->package->forceFill(['backup_schedule' => BackupSchedule::None])->save();
        $this->server->forceFill(['backup_schedule' => BackupSchedule::Daily])->save();
        $this->assertSame(1, $this->runOn('2026-11-04'));
    }

    public function test_servers_that_cannot_take_backups_are_skipped()
    {
        $this->server->forceFill(['backup_slots' => 0])->save();
        $this->assertSame(0, $this->runOn('2026-11-03'));

        $this->server->forceFill(['backup_slots' => 2, 'status' => ServerStatus::Suspended])->save();
        $this->assertSame(0, $this->runOn('2026-11-04'));

        $this->server->forceFill(['status' => ServerStatus::Active])->save();
        Storage::query()->where('name', 'backup-nfs')->update(['use_for_backups' => false]);
        $this->assertSame(0, $this->runOn('2026-11-05'));
        $this->assertSame(0, $this->backupTasks());
        $this->assertNull($this->server->refresh()->backup_scheduled_at);
    }

    public function test_retention_deletes_the_oldest_scheduled_backups_but_never_manual_ones()
    {
        $manual = app(ServerBackups::class)->take($this->server, 'before upgrade', $this->admin, '127.0.0.1');
        $this->assertSame(TaskStatus::Succeeded, $manual->refresh()->status);

        $this->runOn('2026-11-03');
        $first = Backup::query()->where('kind', BackupKind::Scheduled)->sole();
        $this->assertSame([], $this->proxmox->deletedBackupVolumes, 'Two of two slots used.');

        // A third goes over the two slots: the oldest scheduled one makes room.
        $this->runOn('2026-11-04');
        $this->assertSame([$first->volume], $this->proxmox->deletedBackupVolumes);
        $this->assertSame(['manual', 'scheduled'], Backup::query()->orderBy('id')->pluck('kind')->map->value->all());
        $this->assertModelMissing($first);

        $task = Task::query()->where('type', TaskType::CreateBackup)->latest('id')->first();
        $this->assertSame('Older scheduled backups deleted: 1.', collect($task?->steps)->firstWhere('key', 'prune_backups')['message']);
    }

    public function test_a_backup_that_cannot_be_pruned_leaves_a_note_but_the_backup_stays()
    {
        $this->server->forceFill(['backup_slots' => 1])->save();
        $this->runOn('2026-11-03');
        $first = Backup::query()->sole();

        // On a storage no node reaches any more.
        $first->forceFill(['node_id' => null, 'storage' => 'gone'])->save();

        $this->runOn('2026-11-04');

        $task = Task::query()->where('type', TaskType::CreateBackup)->latest('id')->first();
        $this->assertSame(TaskStatus::Succeeded, $task?->status, (string) $task?->error);
        $this->assertStringStartsWith('Older scheduled backups deleted: 0. Not deleted:', (string) collect($task->steps)->firstWhere('key', 'prune_backups')['message']);
        $this->assertSame(2, Backup::query()->count());
    }

    public function test_manual_backups_filling_the_slots_stop_scheduled_ones()
    {
        $backups = app(ServerBackups::class);
        $backups->take($this->server, 'one', $this->admin, null);
        $backups->take($this->server, 'two', $this->admin, null);

        $this->assertSame(0, $this->runOn('2026-11-03'));
        $this->assertSame(2, Backup::query()->count());
        $this->assertSame('backup_slots_full', BackupSchedules::blocked($this->server->refresh())?->error);

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('backups.schedule.effective', 'daily')
                ->where('backups.schedule.own', null)
                ->where('backups.schedule.package', 'daily')
                ->where('backups.schedule.blocked', BackupSchedules::blocked($this->server)?->translatedMessage()));
    }

    public function test_at_most_the_set_number_of_backups_run_at_once()
    {
        $second = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']))->refresh();
        Queue::fake([RunServerTask::class]);

        // One per node.
        $this->assertSame(1, $this->runOn('2026-11-03'));
        $this->assertSame(0, $this->runOn('2026-11-03', '03:50'));

        // Two per node, but the shared storage takes one at a time.
        config(['proxmotion.backups.per_node' => 2, 'proxmotion.backups.per_storage' => 1]);
        $this->assertSame(0, $this->runOn('2026-11-03', '04:00'));

        // Manual backups count too: the queued one finished, a manual one runs.
        Task::query()->where('type', TaskType::CreateBackup)->update(['status' => TaskStatus::Succeeded]);
        app(TaskRunner::class)->start($this->server->refresh(), TaskType::CreateBackup, $this->admin, input: ['storage' => 'backup-nfs', 'kind' => 'manual']);
        $this->assertSame(0, $this->runOn('2026-11-03', '04:10'));

        config(['proxmotion.backups.per_storage' => 2]);
        $this->assertSame(1, $this->runOn('2026-11-03', '04:20'));
        $this->assertNotNull($second->refresh()->backup_scheduled_at);
    }

    public function test_a_busy_server_is_tried_again_at_the_next_run()
    {
        Queue::fake([RunServerTask::class]);
        $verify = app(TaskRunner::class)->start($this->server, TaskType::Verify, $this->admin);

        $this->assertSame(0, $this->runOn('2026-11-03'));
        $this->assertNull($this->server->refresh()->backup_scheduled_at);

        $verify->forceFill(['status' => TaskStatus::Succeeded])->save();
        $this->assertSame(1, $this->runOn('2026-11-03', '03:50'));
    }

    public function test_a_failed_scheduled_backup_notifies_administrators_of_its_own_event()
    {
        config(['proxmotion.notifications.enabled' => true, 'proxmotion.notifications.recipients' => []]);
        Notification::fake();
        $this->proxmox->failBackup = 'storage full';

        $this->assertSame(1, $this->runOn('2026-11-03'));

        $task = Task::query()->where('type', TaskType::CreateBackup)->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        Notification::assertSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::BackupFailed
            && str_contains($alert->toMail($this->admin)->subject, 'web1.example.com'));
        Notification::assertNotSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::TaskFailed);

        // Not tried again in the same window.
        $this->proxmox->failBackup = null;
        $this->assertSame(0, $this->runOn('2026-11-03', '05:00'));
    }

    public function test_backups_of_terminated_servers_are_kept_for_the_set_days()
    {
        config(['proxmotion.backups.keep_terminated_days' => 7]);
        app(ServerBackups::class)->take($this->server, 'keep me', $this->admin, null);
        $backup = Backup::query()->sole();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'UTC'));
        $terminate = app(TaskRunner::class)->start($this->server->refresh(), TaskType::Terminate, $this->admin)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $terminate->status, (string) $terminate->error);
        $this->assertSame('Its backups are kept for 7 days: 1.', collect($terminate->steps)->firstWhere('key', 'delete_backups')['message']);
        $this->assertModelExists($backup);

        // Listed under the terminated server; only deleting is offered.
        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('server.status', 'terminated')->has('backups.items', 1));

        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'UTC'));
        $this->artisan('backups:prune')->assertSuccessful();
        $this->assertModelExists($backup);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'UTC'));
        $this->artisan('backups:prune')->assertSuccessful();
        $this->assertModelMissing($backup);
        $this->assertSame([$backup->volume], $this->proxmox->deletedBackupVolumes);
    }

    public function test_an_administrator_deletes_a_kept_backup_of_a_terminated_server()
    {
        config(['proxmotion.backups.keep_terminated_days' => 30]);
        app(ServerBackups::class)->take($this->server, null, $this->admin, null);
        $backup = Backup::query()->sole();
        app(TaskRunner::class)->start($this->server->refresh(), TaskType::Terminate, $this->admin);

        $this->actingAs($this->admin)
            ->delete(route('admin.servers.backups.destroy', [$this->server, $backup->uuid]))
            ->assertRedirect();

        $this->assertModelMissing($backup);
    }

    public function test_an_administrator_sets_the_servers_own_schedule()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.servers.backup-schedule', $this->server), ['schedule' => 'weekly'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(BackupSchedule::Weekly, $this->server->refresh()->backup_schedule);
        $entry = AuditLog::query()->where('action', 'server.backup_schedule_changed')->sole();
        $this->assertSame(['hostname' => 'web1.example.com', 'from' => null, 'to' => 'weekly'], $entry->properties);

        // Empty: its package's again.
        $this->actingAs($this->admin)->put(route('admin.servers.backup-schedule', $this->server), ['schedule' => null]);
        $this->assertNull($this->server->refresh()->backup_schedule);

        $this->actingAs($this->admin)->put(route('admin.servers.backup-schedule', $this->server), ['schedule' => 'hourly'])
            ->assertSessionHasErrors('schedule');
    }

    public function test_the_package_form_saves_the_schedule()
    {
        $this->actingAs($this->admin)->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page->where('packages.0.backup_schedule', 'daily'));

        $fields = [
            'name' => $this->package->name,
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 40,
            'ipv4_count' => 1,
            'ipv6_count' => 0,
            'snapshot_slots' => 0,
            'backup_slots' => 3,
            'network_models' => ['bridged'],
            'is_active' => true,
            'sort_order' => 0,
        ];

        $this->actingAs($this->admin)->put(route('admin.packages.update', $this->package), [...$fields, 'backup_schedule' => 'weekly'])
            ->assertSessionHasNoErrors();
        $this->assertSame(BackupSchedule::Weekly, $this->package->refresh()->backup_schedule);

        // Left out: unchanged; empty: none.
        $this->actingAs($this->admin)->put(route('admin.packages.update', $this->package), $fields)->assertSessionHasNoErrors();
        $this->assertSame(BackupSchedule::Weekly, $this->package->refresh()->backup_schedule);
        $this->actingAs($this->admin)->put(route('admin.packages.update', $this->package), [...$fields, 'backup_schedule' => null])->assertSessionHasNoErrors();
        $this->assertSame(BackupSchedule::None, $this->package->refresh()->backup_schedule);
    }
}
