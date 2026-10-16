<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Placement\NodePlacer;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reserves everything a new server needs (node, storage, VMID, IPs) and queues
 * the Proxmox work. Reservation happens under a per-location lock inside one
 * transaction, so concurrent orders can't take the same capacity, VMID or IP.
 */
final readonly class ServerCreator
{
    public function __construct(
        private NodePlacer $placer,
        private IpAllocator $ips,
        private ProxmoxClientFactory $clients,
        private TaskRunner $tasks,
    ) {}

    /**
     * @throws ProvisioningException
     */
    public function create(NewServer $order, ?User $actor = null): Server
    {
        if ($order->networkModel === NetworkModel::Nat) {
            throw ProvisioningException::unsupportedNetworkModel();
        }

        $spec = $order->spec;

        if ($spec->ipv4Count + $spec->ipv6Count === 0) {
            throw ProvisioningException::noAddresses('IPv4');
        }

        $server = Cache::lock("provisioning:location:{$order->location->id}", 30)->block(15, function () use ($order, $spec) {
            $ipv4Pool = $this->ips->ipv4Pool($order->location, $order->networkModel, $spec->ipv4Count);
            $ipv6Pool = $this->ips->ipv6Pool($order->location, $order->networkModel, $spec->ipv6Count);
            $bridge = ($ipv4Pool ?? $ipv6Pool)->bridge ?? 'vmbr0';

            $placement = $this->placer->place($order->location, $spec, $order->osTemplate, $bridge);
            $cluster = $placement->node->cluster;
            $vmid = $this->firstFreeVmid($cluster);

            return DB::transaction(function () use ($order, $spec, $placement, $cluster, $vmid, $ipv4Pool, $ipv6Pool) {
                $server = $this->insertServer($order, $placement, $cluster, $vmid);
                $this->ips->assign($server, $ipv4Pool, $spec->ipv4Count, $ipv6Pool, $spec->ipv6Count);

                return $server;
            });
        });

        $this->tasks->start($server, TaskType::Create, $actor);

        return $server;
    }

    private function insertServer(NewServer $order, Placement\Placement $placement, Cluster $cluster, int $vmid): Server
    {
        $server = new Server;
        $server->forceFill([
            'user_id' => $order->owner->id,
            'package_id' => $order->package?->id,
            'location_id' => $order->location->id,
            'cluster_id' => $cluster->id,
            'node_id' => $placement->node->id,
            'storage_id' => $placement->storage->id,
            'os_template_id' => $order->osTemplate->id,
            'vmid' => $vmid,
            'hostname' => strtolower($order->hostname),
            'status' => ServerStatus::Pending,
            'origin' => ServerOrigin::Provisioned,
            'network_model' => $order->networkModel,
            'cpu_cores' => $order->spec->cpuCores,
            'memory_mib' => $order->spec->memoryMib,
            'disk_mib' => $order->spec->diskMib,
            'port_speed_mbps' => $order->spec->portSpeedMbps,
            'traffic_bytes' => $order->spec->trafficBytes,
            'root_password' => $order->rootPassword,
            // The customer may see the initial password once on their server page.
            'root_password_unrevealed' => true,
            'ssh_keys' => $order->sshKeys,
            'external_id' => $order->externalId,
        ])->save();

        return $server;
    }

    /**
     * The first VMID that is free both on Proxmox and in the panel (terminated
     * servers keep their VMID in the panel's history). The per-location lock
     * serialises orders; the unique index on (cluster_id, vmid) is the final guard.
     *
     * @throws ProvisioningException
     */
    private function firstFreeVmid(Cluster $cluster): int
    {
        $proxmox = $this->clients->forCluster($cluster)->cluster();
        $candidate = $proxmox->nextVmid();

        for ($attempt = 0; $attempt < 50; $attempt++, $candidate++) {
            $takenInPanel = Server::query()->where('cluster_id', $cluster->id)->where('vmid', $candidate)->exists();

            if (! $takenInPanel && $proxmox->isVmidFree($candidate)) {
                return $candidate;
            }
        }

        throw new ProvisioningException('No free VMID found on the cluster.');
    }
}
