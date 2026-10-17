<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
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
    public function create(NewServer $order, User|ApiToken|null $actor = null): Server
    {
        if ($order->networkModel === NetworkModel::Nat) {
            throw ProvisioningException::unsupportedNetworkModel();
        }

        $spec = $order->spec;

        if ($spec->ipv4Count + $spec->ipv6Count === 0) {
            throw ProvisioningException::noAddresses('IPv4');
        }

        if ($order->primaryIpv4 !== null && $spec->ipv4Count === 0) {
            throw new ProvisioningException('A primary IPv4 address needs at least one IPv4 address.');
        }

        $server = Cache::lock("provisioning:location:{$order->location->id}", 30)->block(15, function () use ($order, $spec) {
            // A chosen primary decides the pool; the other IPv4 addresses come from the same one.
            $primary = $order->primaryIpv4 === null ? null : $this->ips->freeAddress($order->location, $order->networkModel, IpVersion::V4, $order->primaryIpv4);

            if ($primary !== null && $this->ips->freeIpv4Count($primary->pool) < $spec->ipv4Count) {
                throw ProvisioningException::noAddresses('IPv4');
            }

            $ipv4Pool = $primary->pool ?? $this->ips->ipv4Pool($order->location, $order->networkModel, $spec->ipv4Count);
            $ipv6Pool = $this->ips->ipv6Pool($order->location, $order->networkModel, $spec->ipv6Count);

            // Without an IPv6 pool in the location the server gets IPv4 only, but never no address at all.
            if ($ipv4Pool === null && $ipv6Pool === null) {
                throw ProvisioningException::noAddresses('IPv6');
            }

            $bridge = ($ipv4Pool ?? $ipv6Pool)->bridge ?? 'vmbr0';

            $placement = $this->placer->place($order->location, $spec, $order->osTemplate, $bridge);
            $cluster = $placement->node->cluster;
            $vmid = $this->firstFreeVmid($cluster);

            return DB::transaction(function () use ($order, $spec, $placement, $cluster, $vmid, $ipv4Pool, $ipv6Pool, $primary) {
                $server = $this->insertServer($order, $placement, $cluster, $vmid);

                if ($primary !== null) {
                    $primary->forceFill(['server_id' => $server->id, 'is_primary' => true, 'assigned_at' => now()])->save();
                }

                $this->ips->assign($server, $ipv4Pool, $spec->ipv4Count - ($primary !== null ? 1 : 0), $ipv6Pool, $spec->ipv6Count);

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
            // The billing system may fetch the initial password once (docs/adr/0007).
            'root_password_unrevealed' => true,
            'ssh_keys' => $order->sshKeys,
            'billing_system_id' => $order->billingSystem?->id,
            'external_id' => $order->externalId,
            'external_client_id' => $order->externalClientId,
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
        // Optionally keep the panel's VMs in their own range, apart from VMs managed by hand.
        $candidate = max($proxmox->nextVmid(), (int) config('proxmotion.provisioning.min_vmid'));

        for ($attempt = 0; $attempt < 50; $attempt++, $candidate++) {
            $takenInPanel = Server::query()->where('cluster_id', $cluster->id)->where('vmid', $candidate)->exists();

            if (! $takenInPanel && $proxmox->isVmidFree($candidate)) {
                return $candidate;
            }
        }

        throw new ProvisioningException('No free VMID found on the cluster.');
    }
}
