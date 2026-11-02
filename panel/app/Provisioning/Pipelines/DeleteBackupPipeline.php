<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Models\Backup;
use App\Models\Task;
use App\Proxmox\Data\BackupVolume;
use App\Proxmox\Data\Upid;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;

/**
 * Deletes a backup from its storage (docs/adr/0051 §4), through the cluster
 * and node that have it, which may no longer be the server's. A backup
 * Proxmox no longer has counts as deleted, so running it again is safe.
 */
final readonly class DeleteBackupPipeline
{
    /** How long Proxmox may take to delete a backup file. */
    public const int TIMEOUT_SECONDS = 600;

    public function __construct(private ProxmoxClientFactory $clients) {}

    public function run(Task $task): void
    {
        $backup = Backup::query()
            ->where('server_id', $task->server_id)
            ->where('uuid', (string) ($task->input['backup'] ?? ''))
            ->first() ?? throw new RuntimeException('The backup no longer exists.');

        $this->remove($backup);
        $task->completeStep('delete_backup');
    }

    /**
     * Delete the volume, then the row. Also used when a server is terminated.
     */
    public function remove(Backup $backup): void
    {
        $upid = $this->startRemoval($backup);

        if ($upid !== null) {
            $this->clients->forCluster($backup->cluster)->taskWaiter()->wait($upid, timeoutSeconds: self::TIMEOUT_SECONDS);
        }

        $backup->delete();
    }

    /**
     * Ask Proxmox to delete the volume, without waiting for it: the Proxmox
     * task to wait for, or null when there is none (Proxmox no longer has
     * the volume, or deleted it at once). The row stays; the caller deletes
     * it once the task has finished. Staged callers check the task between
     * runs (CreateBackupPipeline's retention).
     */
    public function startRemoval(Backup $backup): ?Upid
    {
        // A storage that is not shared is reached only from the node it belongs to:
        // once that node is gone, only a shared storage of the same name is the same storage.
        $node = $backup->node->name
            ?? $backup->cluster->nodes()->whereHas('storages', fn ($query) => $query->where('name', $backup->storage)->where('is_shared', true))->value('name')
            ?? throw new RuntimeException("The node that has the storage {$backup->storage} of this backup is gone.");
        $nodes = $this->clients->forCluster($backup->cluster)->nodes();
        $present = collect($nodes->backups($node, $backup->storage, $backup->vmid))
            ->contains(fn (BackupVolume $volume) => $volume->volume === $backup->volume);

        return $present ? $nodes->deleteVolume($node, $backup->storage, $backup->volume) : null;
    }
}
