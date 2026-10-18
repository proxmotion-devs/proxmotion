<?php

declare(strict_types=1);

namespace App\Provisioning\Placement;

use App\Enums\NodeStatus;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\Storage;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ServerSpec;
use Illuminate\Support\Collection;

/**
 * Picks the least-used node in a location that can host a server.
 *
 * A node qualifies when it is online, not in maintenance, belongs to an active
 * cluster (one of the package's clusters, when it names any), has the OS
 * template, has the bridge the server's network uses, and still has room for
 * the server's CPU, memory and disk after over-commit.
 */
final class NodePlacer
{
    /**
     * @param  list<int>  $clusterIds  the clusters the package allows; empty for any
     *
     * @throws ProvisioningException
     */
    public function place(Location $location, ServerSpec $spec, OsTemplate $osTemplate, string $bridge, array $clusterIds = []): Placement
    {
        $templates = $osTemplate->proxmoxTemplates()
            ->whereHas('cluster', fn ($query) => $query->where('location_id', $location->id)->where('is_active', true)
                ->when($clusterIds !== [], fn ($query) => $query->whereIn('id', $clusterIds)))
            ->get();

        if ($templates->isEmpty()) {
            throw $clusterIds === [] ? ProvisioningException::noTemplate() : ProvisioningException::noPackageCluster();
        }

        $nodes = Node::query()
            ->whereIn('cluster_id', $templates->pluck('cluster_id')->unique())
            ->where('status', NodeStatus::Online)
            ->where('is_maintenance', false)
            ->with(['storages', 'bridges'])
            ->get();

        $candidates = [];

        foreach ($nodes as $node) {
            $storage = $this->pickStorage($node, $spec);

            if ($storage === null || ! $this->hasBridge($node, $bridge) || ! $this->fits($node, $spec)) {
                continue;
            }

            $candidates[] = [
                'placement' => new Placement($node, $storage, $this->pickTemplate($templates, $node)),
                'score' => $this->memoryUsageAfter($node, $spec),
            ];
        }

        if ($candidates === []) {
            throw ProvisioningException::noCapacity();
        }

        usort($candidates, fn (array $a, array $b) => $a['score'] <=> $b['score']);

        return $candidates[0]['placement'];
    }

    /**
     * Whether the server's node (and storage) can take the server's new
     * resources: only increases are checked, with the same over-commit rules
     * as placement. The server itself is already counted in what is booked.
     */
    public function fitsChange(Server $server, ServerSpec $target): bool
    {
        $node = $server->node;
        $booked = $this->booked($node);
        $extraCpu = max(0, $target->cpuCores - $server->cpu_cores);
        $extraMemory = max(0, $target->memoryMib - $server->memory_mib);
        $extraDisk = max(0, $target->diskMib - $server->disk_mib);

        if ($extraCpu > 0 && $node->cpu_cores !== null && $node->cpu_cores * (float) $node->cpu_overcommit < $booked['cpu'] + $extraCpu) {
            return false;
        }

        if ($extraMemory > 0 && $node->memory_bytes !== null
            && intdiv($node->memory_bytes, 1024 ** 2) * (float) $node->memory_overcommit < $booked['memory_mib'] + $extraMemory) {
            return false;
        }

        $storage = $server->storage;

        if ($extraDisk > 0 && $storage !== null && $storage->total_bytes !== null) {
            $capacityMib = intdiv($storage->total_bytes, 1024 ** 2) * (float) $node->disk_overcommit;

            return $capacityMib - $this->bookedDiskMib($storage, $node) >= $extraDisk;
        }

        return true;
    }

    private function fits(Node $node, ServerSpec $spec): bool
    {
        $booked = $this->booked($node);

        if ($node->max_servers !== null && $node->max_servers < $booked['count'] + 1) {
            return false;
        }

        if ($node->cpu_cores !== null && $node->cpu_cores * (float) $node->cpu_overcommit < $booked['cpu'] + $spec->cpuCores) {
            return false;
        }

        return $node->memory_bytes === null
            || intdiv($node->memory_bytes, 1024 ** 2) * (float) $node->memory_overcommit >= $booked['memory_mib'] + $spec->memoryMib;
    }

    private function memoryUsageAfter(Node $node, ServerSpec $spec): float
    {
        if (! $node->memory_bytes) {
            return 1.0;
        }

        $capacityMib = intdiv($node->memory_bytes, 1024 ** 2) * (float) $node->memory_overcommit;

        return ($this->booked($node)['memory_mib'] + $spec->memoryMib) / max($capacityMib, 1);
    }

    /**
     * The usable storage with the most free (over-committed) space for the disk.
     */
    private function pickStorage(Node $node, ServerSpec $spec): ?Storage
    {
        $best = null;
        $bestFree = 0;

        foreach ($node->storages as $storage) {
            if (! $storage->use_for_servers || ! $storage->is_available || ! $storage->supportsVmDisks() || $storage->total_bytes === null) {
                continue;
            }

            $capacityMib = intdiv($storage->total_bytes, 1024 ** 2) * (float) $node->disk_overcommit;
            $free = $capacityMib - $this->bookedDiskMib($storage, $node);

            if ($free >= $spec->diskMib && $free > $bestFree) {
                $best = $storage;
                $bestFree = $free;
            }
        }

        return $best;
    }

    /**
     * Disk booked on a storage. Shared storages appear once per node, so
     * servers on the same storage name anywhere in the cluster count.
     */
    private function bookedDiskMib(Storage $storage, Node $node): int
    {
        $query = Server::query()->occupying();

        if ($storage->is_shared) {
            $query->where('cluster_id', $node->cluster_id)
                ->whereHas('storage', fn ($q) => $q->where('name', $storage->name));
        } else {
            $query->where('storage_id', $storage->id);
        }

        return (int) $query->sum('disk_mib');
    }

    /**
     * @return array{count: int, cpu: int, memory_mib: int}
     */
    private function booked(Node $node): array
    {
        $row = Server::query()->occupying()->where('node_id', $node->id)
            ->selectRaw('count(*) as servers, coalesce(sum(cpu_cores), 0) as cpu, coalesce(sum(memory_mib), 0) as memory')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->servers ?? 0),
            'cpu' => (int) ($row->cpu ?? 0),
            'memory_mib' => (int) ($row->memory ?? 0),
        ];
    }

    private function hasBridge(Node $node, string $bridge): bool
    {
        return $node->bridges->contains(fn ($candidate) => $candidate->name === $bridge && $candidate->is_active);
    }

    /**
     * Prefer a template on the node itself (works with local storage); otherwise
     * clone from another node of the cluster (needs shared storage on Proxmox).
     *
     * @param  Collection<int, ProxmoxTemplate>  $templates
     */
    private function pickTemplate(Collection $templates, Node $node): ProxmoxTemplate
    {
        return $templates->firstWhere('node_id', $node->id)
            ?? $templates->where('cluster_id', $node->cluster_id)->firstOrFail();
    }
}
