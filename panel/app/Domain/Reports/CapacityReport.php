<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\NodeStatus;
use App\Models\IpPool;
use App\Models\Node;
use App\Models\Server;
use App\Models\Storage;

/**
 * What the nodes, storages and IP pools hold and can still take, counted
 * like placement counts it (NodePlacer): every server that is not
 * terminated books its resources, over-commit raises the capacity.
 */
final class CapacityReport implements Report
{
    use FiltersByLocation;

    public function rules(): array
    {
        return $this->locationRules();
    }

    public function filters(array $input): array
    {
        return ['location' => $input['location'] ?? null];
    }

    public function filterOptions(): array
    {
        return ['location' => $this->locationOptions()];
    }

    public function tables(array $filters): array
    {
        $locationId = $this->locationId($filters['location']);

        $nodes = Node::query()
            ->with(['cluster.location', 'storages'])
            ->when($locationId !== null, fn ($query) => $query->whereHas('cluster', fn ($query) => $query->where('location_id', $locationId)))
            ->get()
            ->sort(fn (Node $a, Node $b) => [$a->cluster->location->name, $a->cluster->name, $a->name]
                <=> [$b->cluster->location->name, $b->cluster->name, $b->name])
            ->values();

        return [
            $this->nodes(array_values($nodes->all())),
            $this->storages(array_values($nodes->all())),
            $this->ipPools($locationId),
        ];
    }

    /**
     * @param  list<Node>  $nodes
     */
    private function nodes(array $nodes): ReportTable
    {
        $booked = Server::query()->occupying()
            ->groupBy('node_id')
            ->selectRaw('node_id, count(*) as servers, coalesce(sum(cpu_cores), 0) as cpu, coalesce(sum(memory_mib), 0) as memory')
            ->toBase()
            ->get()
            ->keyBy('node_id');

        $rows = [];

        foreach ($nodes as $node) {
            $row = $booked->get($node->id);
            $cpu = (int) ($row->cpu ?? 0);
            $memory = (int) ($row->memory ?? 0);
            $cpuCapacity = $node->cpu_cores === null ? null : round($node->cpu_cores * (float) $node->cpu_overcommit, 2);
            $memoryCapacity = $node->memory_bytes === null
                ? null
                : (int) floor(intdiv($node->memory_bytes, 1024 ** 2) * (float) $node->memory_overcommit);

            $rows[] = [
                'location' => $node->cluster->location->name,
                'cluster' => $node->cluster->name,
                'node' => $node->name,
                'status' => $node->is_maintenance ? __('Maintenance') : match ($node->status) {
                    NodeStatus::Online => __('Online'),
                    NodeStatus::Offline => __('Offline'),
                    NodeStatus::Unknown => __('Unknown'),
                },
                'servers' => (int) ($row->servers ?? 0),
                'max_servers' => $node->max_servers,
                'vcpu_booked' => $cpu,
                'vcpu_capacity' => $cpuCapacity,
                'vcpu_percent' => Reports::percent($cpu, $cpuCapacity),
                'memory_booked_mib' => $memory,
                'memory_capacity_mib' => $memoryCapacity,
                'memory_percent' => Reports::percent($memory, $memoryCapacity),
            ];
        }

        $cpuCapacity = Reports::sum($rows, 'vcpu_capacity');
        $memoryCapacity = Reports::sum($rows, 'memory_capacity_mib');

        return new ReportTable(
            'nodes',
            __('Nodes'),
            __('vCPU cores and memory booked by the servers on each node, against what the node offers after over-commit.'),
            [
                new ReportColumn('location', __('Location')),
                new ReportColumn('cluster', __('Cluster')),
                new ReportColumn('node', __('Node')),
                new ReportColumn('status', __('Status')),
                new ReportColumn('servers', __('Servers'), 'number'),
                new ReportColumn('max_servers', __('Max. servers'), 'number'),
                new ReportColumn('vcpu_booked', __('vCPU booked'), 'number'),
                new ReportColumn('vcpu_capacity', __('vCPU capacity'), 'number'),
                new ReportColumn('vcpu_percent', __('vCPU used'), 'percent'),
                new ReportColumn('memory_booked_mib', __('Memory booked'), 'mib'),
                new ReportColumn('memory_capacity_mib', __('Memory capacity'), 'mib'),
                new ReportColumn('memory_percent', __('Memory used'), 'percent'),
            ],
            $rows,
            $rows === [] ? null : [
                'location' => __('Total'),
                'servers' => Reports::sum($rows, 'servers'),
                'vcpu_booked' => Reports::sum($rows, 'vcpu_booked'),
                'vcpu_capacity' => $cpuCapacity,
                'vcpu_percent' => Reports::percent(Reports::sum($rows, 'vcpu_booked'), $cpuCapacity),
                'memory_booked_mib' => Reports::sum($rows, 'memory_booked_mib'),
                'memory_capacity_mib' => $memoryCapacity,
                'memory_percent' => Reports::percent(Reports::sum($rows, 'memory_booked_mib'), $memoryCapacity),
            ],
        );
    }

    /**
     * Storages servers are placed on, or that hold disks. A shared storage
     * is one row per cluster (it appears on every node); its over-commit is
     * the lowest of those nodes'.
     *
     * @param  list<Node>  $nodes
     */
    private function storages(array $nodes): ReportTable
    {
        $bookedByStorage = Server::query()->occupying()->whereNotNull('storage_id')
            ->groupBy('storage_id')
            ->selectRaw('storage_id, coalesce(sum(disk_mib), 0) as disk')
            ->toBase()
            ->pluck('disk', 'storage_id');

        /** @var array<string, list<array{node: Node, storage: Storage}>> $groups */
        $groups = [];

        foreach ($nodes as $node) {
            foreach ($node->storages as $storage) {
                $key = $storage->is_shared ? "cluster:{$node->cluster_id}:{$storage->name}" : "storage:{$storage->id}";
                $groups[$key][] = ['node' => $node, 'storage' => $storage];
            }
        }

        $rows = [];

        foreach ($groups as $members) {
            $booked = 0;
            $offered = false;
            $overcommit = null;
            $sized = null;

            foreach ($members as ['node' => $node, 'storage' => $storage]) {
                $booked += (int) ($bookedByStorage[$storage->id] ?? 0);
                $offered = $offered || ($storage->use_for_servers && $storage->supportsVmDisks());
                $overcommit = min($overcommit ?? PHP_FLOAT_MAX, (float) $node->disk_overcommit);
                $sized ??= $storage->total_bytes === null ? null : $storage;
            }

            if (! $offered && $booked === 0) {
                continue;
            }

            ['node' => $node, 'storage' => $storage] = $members[0];
            $sizeMib = $sized?->total_bytes === null ? null : intdiv($sized->total_bytes, 1024 ** 2);
            $capacity = $sizeMib === null ? null : (int) floor($sizeMib * $overcommit);

            $rows[] = [
                'location' => $node->cluster->location->name,
                'cluster' => $node->cluster->name,
                'storage' => $storage->name,
                'node' => $storage->is_shared ? __('Shared') : $node->name,
                'type' => $storage->type,
                'size_mib' => $sizeMib,
                'used_mib' => $sized?->used_bytes === null ? null : intdiv($sized->used_bytes, 1024 ** 2),
                'booked_mib' => $booked,
                'capacity_mib' => $capacity,
                'booked_percent' => Reports::percent($booked, $capacity),
            ];
        }

        $capacity = Reports::sum($rows, 'capacity_mib');

        return new ReportTable(
            'storages',
            __('Storages'),
            __('Disks booked on each storage against its size after over-commit, and what Proxmox reports as used.'),
            [
                new ReportColumn('location', __('Location')),
                new ReportColumn('cluster', __('Cluster')),
                new ReportColumn('storage', __('Storage')),
                new ReportColumn('node', __('Node')),
                new ReportColumn('type', __('Type')),
                new ReportColumn('size_mib', __('Size'), 'mib'),
                new ReportColumn('used_mib', __('Used on Proxmox'), 'mib'),
                new ReportColumn('booked_mib', __('Disk booked'), 'mib'),
                new ReportColumn('capacity_mib', __('Disk capacity'), 'mib'),
                new ReportColumn('booked_percent', __('Disk booked %'), 'percent'),
            ],
            $rows,
            $rows === [] ? null : [
                'location' => __('Total'),
                'size_mib' => Reports::sum($rows, 'size_mib'),
                'used_mib' => Reports::sum($rows, 'used_mib'),
                'booked_mib' => Reports::sum($rows, 'booked_mib'),
                'capacity_mib' => $capacity,
                'booked_percent' => Reports::percent(Reports::sum($rows, 'booked_mib'), $capacity),
            ],
        );
    }

    /**
     * IPv4 pools hold every address of their range; IPv6 addresses exist
     * only once assigned, so an IPv6 pool has no size or free count.
     */
    private function ipPools(?int $locationId): ReportTable
    {
        $pools = IpPool::query()
            ->with('location:id,name')
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->withCount([
                'addresses',
                'addresses as assigned_count' => fn ($query) => $query->whereNotNull('server_id'),
                'addresses as reserved_count' => fn ($query) => $query->whereNull('server_id')->where('is_reserved', true),
                'addresses as free_count' => fn ($query) => $query->whereNull('server_id')->whereNull('server_move_id')->where('is_reserved', false),
            ])
            ->get()
            ->sort(fn (IpPool $a, IpPool $b) => [$a->location->name, $a->name] <=> [$b->location->name, $b->name]);

        $rows = [];

        foreach ($pools as $pool) {
            $v4 = $pool->version === IpVersion::V4;
            $size = $v4 ? (int) $pool->getAttribute('addresses_count') : null;
            $assigned = (int) $pool->getAttribute('assigned_count');

            $rows[] = [
                'location' => $pool->location->name,
                'pool' => $pool->name,
                'network' => $pool->network,
                'network_model' => match ($pool->network_model) {
                    NetworkModel::Bridged => __('Bridged public IP'),
                    NetworkModel::Routed => __('Routed / failover IP'),
                    NetworkModel::Nat => __('NAT'),
                },
                'active' => $pool->is_active,
                'addresses' => $size,
                'assigned' => $assigned,
                'reserved' => (int) $pool->getAttribute('reserved_count'),
                'free' => $v4 ? (int) $pool->getAttribute('free_count') : null,
                'assigned_percent' => Reports::percent($assigned, $size),
            ];
        }

        return new ReportTable(
            'ip_pools',
            __('IP pools'),
            __('Addresses assigned to servers, reserved and still free. Addresses held for a server moving to another location are neither free nor assigned.'),
            [
                new ReportColumn('location', __('Location')),
                new ReportColumn('pool', __('Pool')),
                new ReportColumn('network', __('Network')),
                new ReportColumn('network_model', __('Network model')),
                new ReportColumn('active', __('Active'), 'boolean'),
                new ReportColumn('addresses', __('Addresses'), 'number'),
                new ReportColumn('assigned', __('Assigned'), 'number'),
                new ReportColumn('reserved', __('Reserved'), 'number'),
                new ReportColumn('free', __('Free'), 'number'),
                new ReportColumn('assigned_percent', __('Assigned %'), 'percent'),
            ],
            $rows,
        );
    }
}
