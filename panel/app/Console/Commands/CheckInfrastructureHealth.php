<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Infrastructure\PermissionChecker;
use App\Models\Cluster;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('infra:health {cluster? : UUID or name of a single cluster to check}')]
#[Description('Diagnose Proxmox cluster connectivity, node status, and API token permissions')]
class CheckInfrastructureHealth extends Command
{
    public function handle(ProxmoxClientFactory $clients, PermissionChecker $checker): int
    {
        $query = Cluster::query()->where('is_active', true);
        $target = $this->argument('cluster');

        if (is_string($target) && $target !== '') {
            $query->where(fn ($q) => $q->where('uuid', $target)->orWhere('name', $target));
        }

        $clusters = $query->with('nodes')->get();

        if ($clusters->isEmpty()) {
            $this->components->warn('No active clusters found to check.');

            return self::SUCCESS;
        }

        $hasFailures = false;

        foreach ($clusters as $cluster) {
            $this->components->info("Checking cluster: {$cluster->name} ({$cluster->hosts[0] ?? 'no-host'})");

            try {
                $client = $clients->forCluster($cluster);
                $version = $client->version();
                $this->line("  ✓ Proxmox VE connection OK (PVE {$version->release})");
            } catch (ProxmoxException $e) {
                $this->components->error("  ✗ Connection failed: {$e->getMessage()}");
                $hasFailures = true;
                continue;
            }

            try {
                $results = $checker->check($cluster);
                $missingPrivileges = [];

                foreach ($results as $check) {
                    if (! empty($check['missing'])) {
                        $missingPrivileges[] = "{$check['path']} missing: " . implode(', ', $check['missing']);
                    }
                }

                if (empty($missingPrivileges)) {
                    $this->line('  ✓ API token permissions OK');
                } else {
                    $this->components->warn('  ! API token has missing permissions:');
                    foreach ($missingPrivileges as $missing) {
                        $this->line("    - {$missing}");
                    }
                }
            } catch (ProxmoxException $e) {
                $this->components->warn("  ! Permission check failed: {$e->getMessage()}");
            }

            $nodes = $cluster->nodes;
            if ($nodes->isNotEmpty()) {
                $this->line("  Nodes ({$nodes->count()}):");
                foreach ($nodes as $node) {
                    $statusSymbol = $node->status->value === 'online' ? '✓' : '✗';
                    $this->line("    [{$statusSymbol}] {$node->name} ({$node->status->value})");
                }
            }
        }

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }
}
