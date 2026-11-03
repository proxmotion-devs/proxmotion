<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Notifications\AdminNotifier;
use App\Domain\Settings\PanelSettings;
use App\Enums\BackupKind;
use App\Enums\TaskStatus;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Stages\HasOwnDeadline;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Proxmox\Data\BackupVolume;
use App\Proxmox\Data\Upid;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;
use Throwable;

/**
 * Backs a server up with vzdump to the storage chosen when it was requested
 * (docs/adr/0051 §4), then records the backup Proxmox wrote. Staged
 * (docs/adr/0032): a backup can take hours, and no worker waits for it.
 * The VM keeps running (snapshot mode); Proxmox locks it meanwhile, as the
 * task blocks the server's other operations.
 */
final readonly class CreateBackupPipeline implements HasOwnDeadline, StagedPipeline
{
    /** Seconds between checks of a running backup: backups take minutes to hours. */
    private const POLL_SECONDS = 60;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private StageRunner $stages,
        private DeleteBackupPipeline $deletes,
        private AdminNotifier $notifier,
    ) {}

    public function stages(): array
    {
        return ['backup', 'record', 'prune'];
    }

    public function deadlineMinutes(): int
    {
        return (int) PanelSettings::get('backup_deadline_minutes');
    }

    public function runStage(Task $task, string $stage): ?int
    {
        return match ($stage) {
            'backup' => $this->backup($task, $task->server),
            'record' => $this->record($task, $task->server),
            'prune' => $this->prune($task, $task->server),
            default => throw new RuntimeException("Unknown stage {$stage}."),
        };
    }

    /**
     * The notes Proxmox keeps with the backup: they name its server and
     * carry the backup's uuid, by which record() finds it.
     */
    public static function notes(Server $server, string $uuid): string
    {
        return "Proxmotion backup {$uuid} of {$server->hostname} (server {$server->uuid})";
    }

    private function backup(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $node = $server->node->name;

        $starting = $task->stageValue('upid') === null;

        // A task that still holds the VM (a shutdown, a migration) is waited for between runs.
        if ($starting && $client->tasks()->activeForVm($node, $server->vmid) !== []) {
            return 5;
        }

        $delay = $this->stages->proxmoxTask($task, $client, function () use ($task, $client, $server, $node) {
            return $client->nodes()->vzdump(
                $node,
                $server->vmid,
                (string) ($task->input['storage'] ?? ''),
                // Proxmox Backup Server compresses on its own.
                compress: ($task->input['storage_type'] ?? null) !== 'pbs',
                notes: self::notes($server, (string) ($task->input['backup'] ?? '')),
            );
        }, timeoutSeconds: $this->deadlineMinutes() * 60, pollSeconds: self::POLL_SECONDS);

        // An administrator who cancelled while vzdump was being started found no task
        // to stop (TaskFailer::cancel()): stop it here, now that it is known.
        $upid = $task->stageValue('upid');

        if ($starting && is_string($upid) && $task->fresh()?->status !== TaskStatus::Running) {
            $client->tasks()->stop(Upid::parse($upid));

            throw new RuntimeException('The backup was cancelled.');
        }

        if ($delay === null) {
            $task->completeStep('backup');
        }

        return $delay;
    }

    /**
     * Find the volume vzdump wrote: by the uuid in its notes, else the
     * newest one of the VM since the task started that no backup records.
     */
    private function record(Task $task, Server $server): null
    {
        $uuid = (string) ($task->input['backup'] ?? '');
        $storage = (string) ($task->input['storage'] ?? '');
        $client = $this->clients->forCluster($server->cluster);
        $volumes = $client->nodes()->backups($server->node->name, $storage, $server->vmid);
        $known = $server->backups()->where('uuid', '!=', $uuid)->pluck('volume')->all();
        $since = ($task->started_at ?? now())->getTimestamp() - 60;

        $volume = collect($volumes)->first(fn (BackupVolume $volume) => str_contains($volume->notes, $uuid))
            ?? collect($volumes)
                ->filter(fn (BackupVolume $volume) => ! in_array($volume->volume, $known, true) && ($volume->createdAt ?? 0) >= $since)
                ->sortByDesc(fn (BackupVolume $volume) => $volume->createdAt)
                ->first()
            ?? throw new RuntimeException("Proxmox finished the backup, but it is not on the storage {$storage}.");

        $backup = Backup::query()->where('uuid', $uuid)->first() ?? new Backup;
        $backup->forceFill([
            'uuid' => $uuid,
            'server_id' => $server->id,
            'cluster_id' => $server->cluster_id,
            'node_id' => $server->node_id,
            'storage' => $storage,
            'volume' => $volume->volume,
            'vmid' => $volume->vmid ?? $server->vmid,
            'kind' => BackupKind::tryFrom((string) ($task->input['kind'] ?? '')) ?? BackupKind::Manual,
            'label' => is_string($task->input['label'] ?? null) ? $task->input['label'] : null,
            'size_bytes' => $volume->sizeBytes,
            'os_template_id' => $server->os_template_id,
            'hostname' => $server->hostname,
            'disk_mib' => $server->disk_mib,
            'task_id' => $task->id,
        ])->save();

        $task->completeStep('record_backup');

        return null;
    }

    /**
     * Retention (docs/adr/0051 §6): a scheduled backup deletes the oldest
     * scheduled backups beyond the server's slots, one at a time. Manual
     * backups are never deleted here. Proxmox's delete task is checked
     * between runs like the backup's (docs/adr/0032), so no run waits for
     * it. A backup that cannot be deleted is noted and skipped, without
     * failing this backup; when the server is still over its slots at the
     * end, administrators get an email.
     */
    private function prune(Task $task, Server $server): ?int
    {
        if (($task->input['kind'] ?? null) !== BackupKind::Scheduled->value) {
            return null;
        }

        $uuid = $task->stageValue('pruning');
        $backup = is_string($uuid) ? $server->backups()->with(['cluster', 'node'])->where('uuid', $uuid)->first() : null;

        if ($backup === null) {
            $backup = $this->nextToPrune($task, $server);

            if ($backup === null) {
                return $this->finishPruning($task, $server);
            }

            $task->rememberForStage(['pruning' => $backup->uuid]);
        }

        try {
            $delay = $this->stages->proxmoxTask(
                $task,
                $this->clients->forCluster($backup->cluster),
                fn () => $this->deletes->startRemoval($backup),
                timeoutSeconds: DeleteBackupPipeline::TIMEOUT_SECONDS,
                pollSeconds: 5,
            );
        } catch (Throwable $e) {
            $task->rememberForStage([
                'pruning' => null,
                'upid' => null,
                'upid_deadline' => null,
                'failed' => [...$this->stageList($task, 'failed'), $backup->uuid],
                'errors' => [...$this->stageList($task, 'errors'), $e->getMessage()],
            ]);

            return 0;
        }

        if ($delay !== null) {
            return $delay;
        }

        $backup->delete();
        $task->rememberForStage(['pruning' => null, 'deleted' => (int) $task->stageValue('deleted') + 1]);

        // The next one in the next run.
        return 0;
    }

    /**
     * The oldest scheduled backup to delete while the server has more
     * backups than slots: never the new one, nor one that failed already.
     */
    private function nextToPrune(Task $task, Server $server): ?Backup
    {
        if ($server->backups()->count() <= $server->backup_slots) {
            return null;
        }

        return $server->backups()
            ->with(['cluster', 'node'])
            ->where('kind', BackupKind::Scheduled)
            ->where('uuid', '!=', (string) ($task->input['backup'] ?? ''))
            ->whereNotIn('uuid', $this->stageList($task, 'failed'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private function finishPruning(Task $task, Server $server): null
    {
        $deleted = (int) $task->stageValue('deleted');
        $errors = $this->stageList($task, 'errors');
        $excess = $server->backups()->count() - $server->backup_slots;

        $task->completeStep('prune_backups', match (true) {
            $errors !== [] => "Older scheduled backups deleted: {$deleted}. Not deleted: ".implode(' ', $errors),
            $deleted > 0 => "Older scheduled backups deleted: {$deleted}.",
            default => null,
        });

        // Backups that cannot be deleted would otherwise pile up unnoticed, one per scheduled backup.
        if ($excess > 0) {
            $this->notifier->backupsOverSlots($task, $excess, $errors);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stageList(Task $task, string $key): array
    {
        $value = $task->stageValue($key);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
