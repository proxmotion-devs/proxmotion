<?php

declare(strict_types=1);

namespace App\Provisioning\Placement;

use App\Enums\NodeStatus;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\PlacementGroup;
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
 * cluster, has the OS template, has the bridge the server's network uses, and
 * still has room for the server's CPU, memory and disk after over-commit.
 *
 * Administrators may also choose the node, and optionally its storage: the
 * same checks apply to that node, except that a node in maintenance may be
 * chosen (maintenance only keeps automatic placement away from it).
 * Placement groups (docs/adr/0020) limit both to their nodes and storages.
 */
final class NodePlacer
{
    /**
     * @throws ProvisioningException
     */
    public function place(Location $location, ServerSpec $spec, OsTemplate $osTemplate, string $bridge, PlacementRules $rules = new PlacementRules): Placement
    {
        if ($rules->node !== null) {
            return $this->placeOn($rules->node, $location, $spec, $osTemplate, $bridge, $rules);
        }

        $templates = $osTemplate->proxmoxTemplates()
            ->whereHas('cluster', fn ($query) => $query->where('location_id', $location->id)->where('is_active', true))
            ->get();

        if ($templates->isEmpty()) {
            throw ProvisioningException::noTemplate();
        }

        $nodes = Node::query()
            ->whereIn('cluster_id', $templates->pluck('cluster_id')->unique())
            ->where('status', NodeStatus::Online)
            ->where('is_maintenance', false)
            ->when($rules->groups !== null, fn ($query) => $query->whereIn('id', PlacementGroup::nodeIdsOf($rules->groups ?? collect())))
            ->with(['storages', 'bridges'])
            ->get();

        $candidates = [];

        foreach ($nodes as $node) {
            $storage = $this->pickStorage($node, $spec, $rules->groups);

            if ($storage === null || ! $this->hasBridge($node, $bridge) || ! $this->fits($node, $spec)) {
                continue;
            }

            $candidates[] = [
                'placement' => new Placement($node, $storage, $this->pickTemplate($templates, $node)),
                'score' => $this->memoryUsageAfter($node, $spec),
            ];
        }

        if ($candidates === []) {
            throw $rules->groups === null ? ProvisioningException::noCapacity() : ProvisioningException::noGroupCapacity();
        }

        usort($candidates, fn (array $a, array $b) => $a['score'] <=> $b['score']);

        return $candidates[0]['placement'];
    }

    /**
     * Places the server on the node an administrator chose, saying exactly
     * why when it does not fit.
     *
     * @throws ProvisioningException
     */
    private function placeOn(Node $node, Location $location, ServerSpec $spec, OsTemplate $osTemplate, string $bridge, PlacementRules $rules): Placement
    {
        // Fresh from the database: capacity and status may have changed since the node was loaded.
        $node = Node::query()->with(['cluster', 'storages', 'bridges'])->findOrFail($node->id);

        if ($node->cluster->location_id !== $location->id || ! $node->cluster->is_active) {
            throw new ProvisioningException('The chosen node is not in an active cluster of this location.');
        }

        if ($rules->groups !== null && ! in_array($node->id, PlacementGroup::nodeIdsOf($rules->groups), true)) {
            throw new ProvisioningException('The chosen node is not in a placement group this package may use.');
        }

        $templates = $osTemplate->proxmoxTemplates()->where('proxmox_templates.cluster_id', $node->cluster_id)->get();

        if ($templates->isEmpty()) {
            throw new ProvisioningException('This OS template is not available on the cluster of the chosen node.');
        }

        if ($node->status !== NodeStatus::Online) {
            throw new ProvisioningException('The chosen node is not online.');
        }

        if (! $this->hasBridge($node, $bridge)) {
            throw new ProvisioningException('The chosen node does not have the bridge :bridge.', ['bridge' => $bridge]);
        }

        if (! $this->fits($node, $spec)) {
            throw new ProvisioningException('The chosen node does not have enough free CPU, memory or server slots for this server.');
        }

        $storage = $rules->storage === null ? $this->pickStorage($node, $spec, $rules->groups) : $this->chosenStorage($node, $rules->storage, $spec);

        if ($storage === null) {
            throw new ProvisioningException('No storage of the chosen node that is used for servers has room for this disk.');
        }

        return new Placement($node, $storage, $this->pickTemplate($templates, $node));
    }

    /**
     * A storage chosen by name. Unlike automatic placement it need not be
     * marked "use for servers", but it must take VM disks and have room.
     *
     * @throws ProvisioningException
     */
    private function chosenStorage(Node $node, string $name, ServerSpec $spec): Storage
    {
        $storage = $node->storages->firstWhere('name', $name);

        if ($storage === null || ! $storage->is_available || ! $storage->supportsVmDisks()) {
            throw new ProvisioningException('The storage :storage of the chosen node is not available for VM disks.', ['storage' => $name]);
        }

        if ($storage->total_bytes === null || $this->freeDiskMib($storage, $node) < $spec->diskMib) {
            throw new ProvisioningException('The storage :storage does not have room for this disk.', ['storage' => $name]);
        }

        return $storage;
    }

    /**
     * What a node can still take after over-commit, as placement counts it:
     * vCPU cores, memory and servers; null where the node sets no limit (or
     * its size is not known yet).
     *
     * @return array{cpu_cores: float|null, memory_mib: float|null, servers: int|null}
     */
    public function freeCapacity(Node $node): array
    {
        $booked = $this->booked($node);

        return [
            'cpu_cores' => $node->cpu_cores === null ? null : $node->cpu_cores * (float) $node->cpu_overcommit - $booked['cpu'],
            'memory_mib' => $node->memory_bytes === null ? null : intdiv($node->memory_bytes, 1024 ** 2) * (float) $node->memory_overcommit - $booked['memory_mib'],
            'servers' => $node->max_servers === null ? null : $node->max_servers - $booked['count'],
        ];
    }

    /**
     * Over-committed capacity of a storage minus the disks booked on it.
     */
    public function freeDiskMib(Storage $storage, Node $node): float
    {
        if ($storage->total_bytes === null) {
            return 0.0;
        }

        return intdiv($storage->total_bytes, 1024 ** 2) * (float) $node->disk_overcommit - $this->bookedDiskMib($storage, $node);
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
     * The usable storage with the most free (over-committed) space for the disk:
     * one marked "use for servers", or within placement groups one a group of
     * the node allows (docs/adr/0020 §2).
     *
     * @param  Collection<int, PlacementGroup>|null  $groups
     */
    private function pickStorage(Node $node, ServerSpec $spec, ?Collection $groups = null): ?Storage
    {
        $best = null;
        $bestFree = 0;

        foreach ($node->storages as $storage) {
            $allowed = $groups === null
                ? $storage->use_for_servers
                : $groups->contains(fn (PlacementGroup $group) => $group->allowsStorage($storage));

            if (! $allowed || ! $storage->is_available || ! $storage->supportsVmDisks() || $storage->total_bytes === null) {
                continue;
            }

            $free = $this->freeDiskMib($storage, $node);

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
