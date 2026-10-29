<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Models\Backup;
use App\Models\Task;
use App\Proxmox\Data\BackupVolume;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;

/**
 * Deletes a backup from its storage (docs/adr/0051 §4), through the cluster
 * and node that have it, which may no longer be the server's. A backup
 * Proxmox no longer has counts as deleted, so running it again is safe.
 */
final readonly class DeleteBackupPipeline
{
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
        $client = $this->clients->forCluster($backup->cluster);
        // A storage that is not shared is reached only from the node it belongs to:
        // once that node is gone, only a shared storage of the same name is the same storage.
        $node = $backup->node->name
            ?? $backup->cluster->nodes()->whereHas('storages', fn ($query) => $query->where('name', $backup->storage)->where('is_shared', true))->value('name')
            ?? throw new RuntimeException("The node that has the storage {$backup->storage} of this backup is gone.");
        $nodes = $client->nodes();
        $present = collect($nodes->backups($node, $backup->storage, $backup->vmid))
            ->contains(fn (BackupVolume $volume) => $volume->volume === $backup->volume);

        if ($present) {
            $upid = $nodes->deleteVolume($node, $backup->storage, $backup->volume);

            if ($upid !== null) {
                $client->taskWaiter()->wait($upid, timeoutSeconds: 600);
            }
        }

        $backup->delete();
    }
}
