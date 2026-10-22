<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Settings\PanelSettings;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\ServerMove;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Finds a VMID that is free both on Proxmox and in the panel. Terminated
 * servers keep their VMID in the panel's history, and moves into a cluster
 * hold their target VMID until they are recorded (docs/adr/0037 §4). Callers
 * hold the location's provisioning lock; the unique index on
 * (cluster_id, vmid) is the final guard.
 */
final readonly class VmidAllocator
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * $preferred when it is free, else the first free VMID from Proxmox's
     * next free one (and PROXMOTION_MIN_VMID) upwards.
     *
     * @throws ProvisioningException
     */
    public function firstFree(Cluster $cluster, ?int $preferred = null): int
    {
        $proxmox = $this->clients->forCluster($cluster)->cluster();
        $minimum = (int) PanelSettings::get('min_vmid');
        $held = ServerMove::heldVmids($cluster);
        $isFree = fn (int $vmid): bool => ! in_array($vmid, $held, true)
            && ! Server::query()->where('cluster_id', $cluster->id)->where('vmid', $vmid)->exists()
            && $proxmox->isVmidFree($vmid);

        if ($preferred !== null && $preferred >= $minimum && $isFree($preferred)) {
            return $preferred;
        }

        // Optionally keep the panel's VMs in their own range, apart from VMs managed by hand.
        $candidate = max($proxmox->nextVmid(), $minimum);

        for ($attempt = 0; $attempt < 50; $attempt++, $candidate++) {
            if ($isFree($candidate)) {
                return $candidate;
            }
        }

        throw new ProvisioningException('No free VMID found on the cluster.');
    }
}
