<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Models\Cluster;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infra:sync {cluster? : UUID of a single cluster to sync}')]
#[Description('Sync nodes, storages, bridges and templates from Proxmox')]
class SyncInfrastructure extends Command
{
    public function handle(ClusterSynchronizer $synchronizer): int
    {
        $query = Cluster::query()->where('is_active', true);
        $uuid = $this->argument('cluster');

        if (is_string($uuid)) {
            $query->where('uuid', $uuid);
        }

        $failed = false;

        foreach ($query->get() as $cluster) {
            try {
                $synchronizer->sync($cluster);
                $this->components->info("Synced {$cluster->name}.");
            } catch (ProxmoxException $e) {
                $failed = true;
                $this->components->error("{$cluster->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
