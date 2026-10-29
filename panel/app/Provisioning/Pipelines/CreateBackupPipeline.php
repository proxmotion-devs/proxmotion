<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Settings\PanelSettings;
use App\Enums\BackupKind;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Stages\HasOwnDeadline;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Proxmox\Data\BackupVolume;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;

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
    ) {}

    public function stages(): array
    {
        return ['backup', 'record'];
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

        // A task that still holds the VM (a shutdown, a migration) is waited for between runs.
        if ($task->stageValue('upid') === null && $client->tasks()->activeForVm($node, $server->vmid) !== []) {
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
}
