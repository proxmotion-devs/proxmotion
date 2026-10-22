<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClient;

/**
 * Resource pools.
 */
final readonly class PoolsResource
{
    public function __construct(private ProxmoxClient $client) {}

    /**
     * Put a guest into a pool; one already in it stays. A remote migration
     * puts the moved VM into no pool, so the panel's pool-limited token
     * cannot see it until it is added (docs/adr/0037 §5).
     */
    public function addVm(string $pool, int $vmid): void
    {
        try {
            $this->client->put('/pools/'.rawurlencode($pool), ['vms' => (string) $vmid]);
        } catch (ProxmoxRequestFailed $e) {
            // "VM 123 is already a pool member"
            if (! str_contains($e->getMessage(), 'already')) {
                throw $e;
            }
        }
    }
}
