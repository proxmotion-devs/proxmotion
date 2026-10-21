<?php

declare(strict_types=1);

namespace App\Provisioning\Network;

use App\Domain\Networking\ProtectedAddresses;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Server;
use App\Proxmox\Resources\QemuResource;
use App\Support\Network\Cidr;

/**
 * The IP sets that keep a server away from its neighbours and from the
 * infrastructure (docs/adr/0034): the networks of its pools that isolate
 * guests, their gateways (still reachable), and the protected networks — the
 * Proxmox nodes of its location, the location's protected networks and
 * PROXMOTION_PROTECTED_NETWORKS. The rules using them are
 * QemuResource::ISOLATION_RULES.
 */
final readonly class GuestIsolation
{
    public function __construct(private ProtectedAddresses $protected) {}

    /**
     * @return array<string, list<string>> IP set name => entries, in one spelling (Cidr::canonical())
     */
    public function sets(Server $server): array
    {
        $pools = IpPool::query()
            ->whereIn('id', $server->ipAddresses()->select('ip_pool_id'))
            ->where('isolate_guests', true)
            ->orderBy('id')
            ->get();

        $isolated = [];
        $gateways = [];

        foreach ($pools as $pool) {
            $isolated[] = Cidr::canonical($pool->network);

            if ($pool->gateway !== null) {
                $gateways[] = Cidr::canonical($pool->gateway);
            }
        }

        return [
            QemuResource::ISOLATED_SET => array_values(array_unique($isolated)),
            QemuResource::GATEWAYS_SET => array_values(array_unique($gateways)),
            QemuResource::PROTECTED_SET => $this->protectedNetworks($server->location_id),
        ];
    }

    /**
     * @return list<string>
     */
    private function protectedNetworks(int $locationId): array
    {
        /** @var list<string> $configured */
        $configured = config('proxmotion.protected_networks', []);

        $entries = [
            ...$this->protected->nodeAddresses($locationId),
            ...(Location::query()->find($locationId)->protected_networks ?? []),
            ...$configured,
        ];

        $entries = array_filter($entries, fn (string $entry) => Cidr::isAddressOrNetwork($entry));

        return array_values(array_unique(array_map(Cidr::canonical(...), $entries)));
    }
}
