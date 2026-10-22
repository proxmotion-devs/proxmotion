<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far a server moves (docs/adr/0037).
 */
enum ServerMoveKind: string
{
    /** To another node of its cluster: Proxmox's migrate, addresses and VMID kept. */
    case Node = 'node';

    /** To another cluster of its location: remote_migrate, addresses kept. */
    case Cluster = 'cluster';

    /** To a cluster of another location: remote_migrate while stopped, new addresses. */
    case Location = 'location';

    public function crossesClusters(): bool
    {
        return $this !== self::Node;
    }
}
