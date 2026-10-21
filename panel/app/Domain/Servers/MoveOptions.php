<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\IpVersion;
use App\Enums\NodeStatus;
use App\Models\IpPool;
use App\Models\Node;
use App\Models\PlacementGroup;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\Storage;
use App\Provisioning\IpAllocator;
use App\Provisioning\Moves\ServerMover;
use App\Provisioning\Placement\NodePlacer;

/**
 * What the "Move server" dialog offers (docs/adr/0037): every other node with
 * its storages and why it cannot take the server, the placement groups, and
 * the IPv4 pools of other locations for the server's network model; why the
 * server cannot move now, whether it has routed addresses, and its recent moves.
 */
final readonly class MoveOptions
{
    public function __construct(
        private NodePlacer $placer,
        private IpAllocator $ips,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Server $server): array
    {
        $refusal = ServerMover::serverRefusal($server);
        $nodes = Node::query()
            ->where('id', '!=', $server->node_id)
            ->whereHas('cluster', fn ($query) => $query->where('is_active', true))
            ->with(['cluster.location:id,uuid,name', 'storages' => fn ($query) => $query->where('is_available', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return [
            'refusal' => $refusal === null ? null : self::translate($refusal),
            'cross_cluster' => ServerMover::crossClusterEnabled(),
            'routed' => ServerMover::hasRoutedAddresses($server),
            'location' => $server->location->uuid,
            'cluster' => $server->cluster->uuid,
            'nodes' => $nodes->map(fn (Node $node) => [
                'value' => $node->uuid,
                'label' => "{$node->name} ({$node->cluster->name})",
                'location' => $node->cluster->location->uuid,
                'location_name' => $node->cluster->location->name,
                'cluster' => $node->cluster->uuid,
                'online' => $node->status === NodeStatus::Online,
                'maintenance' => $node->is_maintenance,
                'refusal' => $this->nodeRefusal($server, $node),
                'storages' => $node->storages
                    ->filter(fn (Storage $storage) => $storage->supportsVmDisks())
                    ->map(fn (Storage $storage) => [
                        'name' => $storage->name,
                        'shared' => $storage->is_shared,
                        'use_for_servers' => $storage->use_for_servers,
                        'free_mib' => max(0, (int) $this->placer->freeDiskMib($storage, $node)),
                    ])->values(),
            ])->values(),
            'placementGroups' => PlacementGroup::query()->with('location:id,uuid,name')->orderBy('name')->get()
                ->map(fn (PlacementGroup $group) => [
                    'value' => $group->uuid,
                    'label' => $group->name,
                    'location' => $group->location->uuid,
                    'location_name' => $group->location->name,
                ])->values(),
            'ipv4Pools' => IpPool::query()
                ->where('location_id', '!=', $server->location_id)
                ->where('version', IpVersion::V4)
                ->where('network_model', $server->network_model)
                ->where('is_active', true)
                ->with('location:id,uuid')
                ->orderBy('name')
                ->get()
                ->map(fn (IpPool $pool) => [
                    'value' => $pool->uuid,
                    'label' => "{$pool->name} ({$pool->network})",
                    'location' => $pool->location->uuid,
                    'free' => $this->ips->freeIpv4Count($pool),
                ])->values(),
            'moves' => $server->moves()->with(['sourceCluster:id,name', 'sourceNode:id,name', 'targetCluster:id,name', 'targetNode:id,name'])->limit(5)->get()
                ->map(fn (ServerMove $move) => [
                    'uuid' => $move->uuid,
                    'kind' => $move->kind,
                    'status' => $move->status,
                    'from' => "{$move->sourceNode->name} ({$move->sourceCluster->name}) · VM {$move->source_vmid}",
                    'to' => "{$move->targetNode->name} ({$move->targetCluster->name}) · VM {$move->target_vmid}",
                    'error' => $move->error,
                    'can_finish' => $move->canFinish(),
                    'created_at' => $move->created_at?->toIso8601String(),
                    'finished_at' => $move->finished_at?->toIso8601String(),
                ])->values(),
        ];
    }

    /**
     * Why the node cannot take the server, or null (capacity is checked on submit).
     */
    private function nodeRefusal(Server $server, Node $node): ?string
    {
        $reason = match (true) {
            $node->status !== NodeStatus::Online => 'The node is not online.',
            default => $node->cluster->guestFirewallProblem() ?? ServerMover::clusterRefusal($server, $node->cluster),
        };

        return $reason === null ? null : self::translate($reason);
    }

    /**
     * An English key in the administrator's language (the key itself when it has no translation).
     */
    private static function translate(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
