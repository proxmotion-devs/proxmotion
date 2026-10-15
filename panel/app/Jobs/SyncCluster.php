<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Models\Cluster;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCluster implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Cluster $cluster) {}

    public function uniqueId(): string
    {
        return (string) $this->cluster->id;
    }

    public function handle(ClusterSynchronizer $synchronizer): void
    {
        try {
            $synchronizer->sync($this->cluster);
        } catch (ProxmoxException) {
            // Already recorded on the cluster (last_sync_error) and shown in the admin panel.
        }
    }
}
