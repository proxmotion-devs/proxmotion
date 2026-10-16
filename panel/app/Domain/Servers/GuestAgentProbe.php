<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Whether a server's QEMU guest agent answers. Password reset, reinstall
 * readiness and the App Marketplace depend on it.
 */
final readonly class GuestAgentProbe
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * True or false, or null when Proxmox itself cannot be asked right now.
     */
    public function reachable(Server $server): ?bool
    {
        try {
            // Pages load the cluster with only a few columns; the connection needs all of them.
            return $this->clients->forCluster($server->cluster()->firstOrFail())->guestAgent($server->node->name)->ping($server->vmid);
        } catch (ProxmoxException) {
            return null;
        }
    }
}
