<?php

declare(strict_types=1);

namespace App\Domain\Migration;

use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\Node;
use App\Models\Package;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Proxmox\Data\VmResource;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\DB;

/**
 * Takes over an existing Proxmox VM: records it as a server managed by the panel.
 * Nothing is changed on Proxmox — the VM is not restarted or reconfigured.
 */
final readonly class AdoptServer
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * VMs in the cluster (and its resource pool, if set) that the panel doesn't manage yet.
     *
     * @return list<VmResource>
     */
    public function candidates(Cluster $cluster): array
    {
        $managed = Server::query()->occupying()->where('cluster_id', $cluster->id)->pluck('vmid')->all();

        return array_values(array_filter(
            $this->clients->forCluster($cluster)->cluster()->guests(),
            fn (VmResource $vm) => $vm->type === 'qemu'
                && ! $vm->template
                && ($cluster->resource_pool === null || $vm->pool === $cluster->resource_pool)
                && ! in_array($vm->vmid, $managed, true),
        ));
    }

    /**
     * @param  list<int>  $ipAddressIds  free addresses of the cluster's location the VM already uses
     *
     * @throws ProvisioningException
     */
    public function adopt(Cluster $cluster, int $vmid, ?Package $package, NetworkModel $networkModel, array $ipAddressIds, ?User $actor = null, ?string $externalId = null, ?string $externalClientId = null): Server
    {
        $vm = collect($this->candidates($cluster))->firstWhere('vmid', $vmid)
            ?? throw new ProvisioningException('VM :vmid was not found, is already managed, or is not in the resource pool.', ['vmid' => (string) $vmid]);

        $node = Node::query()->where('cluster_id', $cluster->id)->where('name', $vm->node)->first()
            ?? throw new ProvisioningException('The node of this VM has not been synced yet. Sync the cluster first.');

        return DB::transaction(function () use ($cluster, $vm, $node, $package, $networkModel, $ipAddressIds, $actor, $externalId, $externalClientId) {
            $addresses = IpAddress::query()
                ->whereIn('id', $ipAddressIds)
                ->whereNull('server_id')
                ->whereHas('pool', fn ($query) => $query->where('location_id', $cluster->location_id))
                ->lockForUpdate()
                ->get();

            if ($addresses->count() !== count(array_unique($ipAddressIds))) {
                throw new ProvisioningException('Some of the selected IP addresses are not free or belong to another location.');
            }

            $server = new Server;
            $server->forceFill([
                'external_id' => $externalId,
                'external_client_id' => $externalClientId,
                'package_id' => $package?->id,
                'location_id' => $cluster->location_id,
                'cluster_id' => $cluster->id,
                'node_id' => $node->id,
                'vmid' => $vm->vmid,
                'hostname' => $vm->name ?? "vm-{$vm->vmid}",
                'status' => ServerStatus::Active,
                'origin' => ServerOrigin::Adopted,
                'network_model' => $networkModel,
                // Record what the VM really has; the package is what the customer pays for.
                'cpu_cores' => $vm->cpus ?? $package->cpu_cores ?? 1,
                'memory_mib' => $vm->memoryBytes !== null ? intdiv($vm->memoryBytes, 1024 ** 2) : ($package->memory_mib ?? 0),
                'disk_mib' => $vm->diskBytes !== null ? intdiv($vm->diskBytes, 1024 ** 2) : ($package->disk_mib ?? 0),
                'port_speed_mbps' => $package?->port_speed_mbps,
                'traffic_bytes' => $package?->traffic_bytes,
                'provisioned_at' => now(),
            ])->save();

            foreach ($addresses as $address) {
                $address->forceFill(['server_id' => $server->id, 'assigned_at' => now()])->save();
            }

            $task = new Task;
            $task->forceFill([
                'server_id' => $server->id,
                'user_id' => $actor?->id,
                'type' => TaskType::Adopt,
                'status' => TaskStatus::Succeeded,
                'steps' => [['key' => 'adopt', 'status' => 'done', 'message' => null, 'finished_at' => now()->toIso8601String()]],
                'started_at' => now(),
                'finished_at' => now(),
            ])->save();

            return $server;
        });
    }
}
