<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Networking\ProtectedAddresses;
use App\Enums\ClusterType;
use App\Enums\NodeStatus;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\Node;
use App\Proxmox\Data\BridgeInfo;
use App\Proxmox\Data\NodeInfo;
use App\Proxmox\Data\StorageInfo;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Reads a cluster's nodes, storages, bridges and VM templates from Proxmox and
 * mirrors them in the database. Things that disappeared from Proxmox are marked
 * as unavailable rather than deleted (except templates, which have no state of their own).
 */
final readonly class ClusterSynchronizer
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private ProtectedAddresses $protected,
        private DriftDetector $drift,
        private FirewallChecker $firewall,
    ) {}

    /**
     * @throws ProxmoxException when Proxmox can't be reached or rejects the request
     */
    public function sync(Cluster $cluster): void
    {
        $client = $this->clients->forCluster($cluster);

        try {
            $version = $client->version();
            $status = $client->cluster()->status();
            $nodes = $client->nodes()->list();
            $storages = $client->cluster()->storages();
            $guests = $client->cluster()->guests();
            $bridges = $this->fetchBridges($client, $nodes);
            $firewall = $client->cluster()->firewall();
        } catch (ProxmoxException $e) {
            $cluster->forceFill(['last_sync_error' => $e->getMessage()])->save();

            throw $e;
        }

        DB::transaction(function () use ($cluster, $version, $status, $nodes, $storages, $guests, $bridges, $firewall) {
            $cluster->forceFill([
                'type' => $status->isCluster() ? ClusterType::Cluster : ClusterType::Standalone,
                'proxmox_cluster_name' => $status->clusterName,
                'pve_version' => $version !== '' ? $version : null,
                'last_synced_at' => now(),
                'last_sync_error' => null,
                // Without these the VM firewalls stop no spoofing (docs/adr/0033).
                'firewall_enabled' => $firewall->enabled,
                'firewall_ebtables' => $firewall->ebtables,
            ])->save();

            $nodeModels = $this->syncNodes($cluster, $nodes);
            $this->syncStorages($nodeModels, $storages);
            $this->syncBridges($nodeModels, $bridges);
            $this->syncTemplates($cluster, $nodeModels, $guests);
            $this->reserveNodeAddresses($cluster);
        });

        $this->drift->detect($cluster, $guests, $this->firewall->check($cluster, $guests));
    }

    /**
     * @param  list<NodeInfo>  $nodes
     * @return array<string, list<BridgeInfo>>
     */
    private function fetchBridges(ProxmoxClient $client, array $nodes): array
    {
        $bridges = [];

        foreach ($nodes as $node) {
            if ($node->online) {
                $bridges[$node->name] = $client->nodes()->bridges($node->name);
            }
        }

        return $bridges;
    }

    /**
     * @param  list<NodeInfo>  $nodes
     * @return array<string, Node> keyed by node name
     */
    private function syncNodes(Cluster $cluster, array $nodes): array
    {
        $models = [];

        foreach ($nodes as $info) {
            // Offline nodes don't report resources: keep the last known values.
            $values = array_filter([
                'cpu_cores' => $info->cpuCores,
                'memory_bytes' => $info->memoryBytes,
                'last_seen_at' => $info->online ? now() : null,
            ], fn ($value) => $value !== null);

            $models[$info->name] = $this->upsert($cluster->nodes(), ['name' => $info->name], [
                'status' => $info->online ? NodeStatus::Online : NodeStatus::Offline,
                ...$values,
            ]);
        }

        // Nodes Proxmox no longer reports (removed from the cluster, renamed…).
        $cluster->nodes()
            ->whereNotIn('name', array_keys($models))
            ->update(['status' => NodeStatus::Unknown]);

        return $models;
    }

    /**
     * @param  array<string, Node>  $nodes
     * @param  list<StorageInfo>  $storages
     */
    private function syncStorages(array $nodes, array $storages): void
    {
        $seen = [];

        foreach ($storages as $info) {
            $node = $nodes[$info->node] ?? null;

            if ($node === null) {
                continue;
            }

            $this->upsert($node->storages(), ['name' => $info->name], [
                'type' => $info->type,
                'content' => $info->content,
                'is_shared' => $info->shared,
                'is_available' => $info->available,
                'total_bytes' => $info->totalBytes,
                'used_bytes' => $info->usedBytes,
            ]);

            $seen[$node->id][] = $info->name;
        }

        foreach ($nodes as $node) {
            $node->storages()
                ->whereNotIn('name', $seen[$node->id] ?? [])
                ->update(['is_available' => false]);
        }
    }

    /**
     * @param  array<string, Node>  $nodes
     * @param  array<string, list<BridgeInfo>>  $bridges
     */
    private function syncBridges(array $nodes, array $bridges): void
    {
        foreach ($bridges as $nodeName => $nodeBridges) {
            $node = $nodes[$nodeName] ?? null;

            if ($node === null) {
                continue;
            }

            foreach ($nodeBridges as $info) {
                $this->upsert($node->bridges(), ['name' => $info->name], [
                    'is_active' => $info->active,
                    'is_vlan_aware' => $info->vlanAware,
                    'cidr' => $info->cidr,
                    'comments' => $info->comments,
                ]);
            }

            $node->bridges()
                ->whereNotIn('name', array_map(fn ($bridge) => $bridge->name, $nodeBridges))
                ->update(['is_active' => false]);
        }
    }

    /**
     * @param  array<string, Node>  $nodes
     * @param  list<VmResource>  $guests
     */
    private function syncTemplates(Cluster $cluster, array $nodes, array $guests): void
    {
        $vmids = [];

        foreach ($guests as $guest) {
            $node = $nodes[$guest->node] ?? null;

            if (! $guest->template || $guest->type !== 'qemu' || $node === null) {
                continue;
            }

            $this->upsert($cluster->proxmoxTemplates(), ['vmid' => $guest->vmid], [
                'node_id' => $node->id,
                'name' => $guest->name,
            ]);

            $vmids[] = $guest->vmid;
        }

        $cluster->proxmoxTemplates()->whereNotIn('vmid', $vmids)->delete();
    }

    /**
     * A node's own IP must never be given to a server: reserve it in any pool
     * that contains it (unless an admin already assigned it to a server).
     */
    private function reserveNodeAddresses(Cluster $cluster): void
    {
        $addresses = $this->protected->nodeAddresses($cluster->location_id);

        if ($addresses === []) {
            return;
        }

        IpAddress::query()
            ->whereIn('address', $addresses)
            ->whereNull('server_id')
            ->where('is_reserved', false)
            ->update(['is_reserved' => true, 'notes' => 'Proxmox node']);
    }

    /**
     * updateOrCreate() without mass-assignment protection: these columns are
     * owned by the sync and deliberately not fillable from requests.
     *
     * @template TModel of Model
     * @template TParent of Model
     *
     * @param  HasMany<TModel, TParent>  $relation
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    private function upsert(HasMany $relation, array $keys, array $values): Model
    {
        $model = (clone $relation->getQuery())->where($keys)->first() ?? $relation->make();
        $model->forceFill([...$keys, ...$values])->save();

        return $model;
    }
}
