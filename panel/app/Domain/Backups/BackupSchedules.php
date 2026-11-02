<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Settings\PanelSettings;
use App\Enums\BackupKind;
use App\Enums\BackupSchedule;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Storage;
use App\Models\Task;
use App\Provisioning\Exceptions\ProvisioningException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scheduled backups (docs/adr/0051 §6). A server follows its package's
 * schedule unless it has its own; due servers are backed up once per
 * backup window (Panel settings), daily or on their weekday, spread over the
 * window's first half by id so the second half is left for those that had
 * to wait. At most backups_per_node run on a node and backups_per_storage on
 * a backup storage at a time, manual ones included. A server that is busy
 * or over those limits is tried again at the next run until the window
 * ends; each one queued is not queued again in the same window, also when
 * its backup fails.
 */
final readonly class BackupSchedules
{
    /** Due servers are this many seconds apart, as often as backups:schedule runs. */
    public const int STEP_SECONDS = 600;

    public function __construct(private ServerBackups $backups) {}

    /**
     * The schedule that applies: the server's own, else its package's.
     */
    public static function of(Server $server): BackupSchedule
    {
        return $server->backup_schedule ?? $server->package->backup_schedule ?? BackupSchedule::None;
    }

    /**
     * Why the schedule backs the server up no more (translatedMessage() for
     * pages), or null. Not when it has no schedule.
     */
    public static function blocked(Server $server): ?BackupsUnavailable
    {
        if (self::of($server) === BackupSchedule::None) {
            return null;
        }

        return ServerBackups::unavailable($server)
            ?? ($server->backups()->where('kind', BackupKind::Manual)->count() >= $server->backup_slots ? BackupsUnavailable::manualSlotsFull() : null);
    }

    /**
     * The backup window that $at falls into, or null outside one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function windowAt(CarbonInterface $at): ?array
    {
        $at = CarbonImmutable::instance($at);

        // A window that ends on the next day may have started yesterday.
        foreach ([0, -1] as $days) {
            [$start, $end] = self::windowOn($at->addDays($days));

            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return [$start, $end];
            }
        }

        return null;
    }

    /**
     * When the server's next scheduled backup is due, or null when it has
     * no schedule. A time in the past: at the next run of backups:schedule.
     */
    public static function nextAt(Server $server, CarbonInterface $now): ?CarbonImmutable
    {
        $schedule = self::of($server);

        if ($schedule === BackupSchedule::None) {
            return null;
        }

        $now = CarbonImmutable::instance($now);

        // Yesterday's window may still be open; a weekly one comes within eight days.
        for ($days = -1; $days <= 7; $days++) {
            [$start, $end] = self::windowOn($now->addDays($days));

            if ($end->lessThanOrEqualTo($now) || ! self::onItsDay($server, $schedule, $start) || self::queuedIn($server, $start)) {
                continue;
            }

            return $start->addSeconds(self::offsetSeconds($server, $start, $end));
        }

        return null;
    }

    /**
     * Queue the backups due now. Called by backups:schedule every ten
     * minutes; does nothing outside the window.
     *
     * @return int how many were queued
     */
    public function queueDue(CarbonInterface $now): int
    {
        $window = self::windowAt($now);

        if ($window === null) {
            return 0;
        }

        [$start, $end] = $window;
        $perNode = (int) PanelSettings::get('backups_per_node');
        $perStorage = (int) PanelSettings::get('backups_per_storage');
        [$nodes, $storages] = $this->running();
        $queued = 0;

        $servers = Server::query()
            ->with(['package', 'node'])
            ->where('status', ServerStatus::Active)
            ->where('backup_slots', '>', 0)
            ->where(fn (Builder $query) => $query
                ->whereIn('backup_schedule', [BackupSchedule::Daily, BackupSchedule::Weekly])
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('backup_schedule')
                    ->whereHas('package', fn (Builder $query) => $query->whereIn('backup_schedule', [BackupSchedule::Daily, BackupSchedule::Weekly]))))
            ->where(fn (Builder $query) => $query->whereNull('backup_scheduled_at')->orWhere('backup_scheduled_at', '<', $start))
            ->orderBy('id')
            ->get();

        foreach ($servers as $server) {
            if (! self::onItsDay($server, self::of($server), $start)
                || $now->lessThan($start->addSeconds(self::offsetSeconds($server, $start, $end)))
                || ($nodes[$server->node_id] ?? 0) >= $perNode) {
                continue;
            }

            $storage = ServerBackups::storageFor($server);

            if ($storage === null || ($storages[self::storageKey($server, $storage)] ?? 0) >= $perStorage) {
                continue;
            }

            try {
                $this->backups->takeScheduled($server, $storage);
            } catch (ProvisioningException) {
                // Busy, or its manual backups fill its slots: tried again at the next run.
                continue;
            }

            $server->forceFill(['backup_scheduled_at' => $now])->save();
            $nodes[$server->node_id] = ($nodes[$server->node_id] ?? 0) + 1;
            $storages[self::storageKey($server, $storage)] = ($storages[self::storageKey($server, $storage)] ?? 0) + 1;
            $queued++;
        }

        return $queued;
    }

    /**
     * The window that starts on $day, in the panel's time zone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function windowOn(CarbonImmutable $day): array
    {
        $day = $day->setTimezone((string) config('app.timezone'));
        $start = self::at($day, (string) PanelSettings::get('backup_window_start'));
        $end = self::at($day, (string) PanelSettings::get('backup_window_end'));

        return [$start, $end->lessThanOrEqualTo($start) ? $end->addDay() : $end];
    }

    private static function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time.':0'));

        return $day->setTime($hour, $minute);
    }

    /**
     * How long after the window's start the server is due: servers ten
     * minutes apart by id over the window's first half.
     */
    private static function offsetSeconds(Server $server, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $half = max(self::STEP_SECONDS, intdiv($end->getTimestamp() - $start->getTimestamp(), 2));

        return ($server->id * self::STEP_SECONDS) % $half;
    }

    /**
     * Weekly servers have a weekday of their own, from their id.
     */
    private static function onItsDay(Server $server, BackupSchedule $schedule, CarbonImmutable $start): bool
    {
        return $schedule !== BackupSchedule::Weekly || $start->dayOfWeekIso === $server->id % 7 + 1;
    }

    private static function queuedIn(Server $server, CarbonImmutable $start): bool
    {
        return $server->backup_scheduled_at !== null && $server->backup_scheduled_at->greaterThanOrEqualTo($start);
    }

    /**
     * Backups running now, manual ones included, per node and per backup storage.
     *
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function running(): array
    {
        $nodes = [];
        $storages = [];
        $tasks = Task::query()
            ->with('server.node')
            ->where('type', TaskType::CreateBackup)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->get();

        foreach ($tasks as $task) {
            $server = $task->server;
            $nodes[$server->node_id] = ($nodes[$server->node_id] ?? 0) + 1;
            $storage = $server->node->storages()->where('name', (string) ($task->input['storage'] ?? ''))->first();

            if ($storage !== null) {
                $storages[self::storageKey($server, $storage)] = ($storages[self::storageKey($server, $storage)] ?? 0) + 1;
            }
        }

        return [$nodes, $storages];
    }

    /**
     * A shared storage is one per cluster; any other is its node's own.
     */
    private static function storageKey(Server $server, Storage $storage): string
    {
        return $storage->is_shared ? "cluster:{$server->cluster_id}:{$storage->name}" : "node:{$storage->node_id}:{$storage->name}";
    }
}
