<?php

declare(strict_types=1);

namespace App\Domain\Networking;

use App\Models\IpPool;
use App\Models\NetworkBridge;
use App\Support\Network\Cidr;

/**
 * Addresses that must never be handed to a server: the pool's gateway, the
 * network and broadcast address, and the IPs of Proxmox nodes in the same
 * location (learned from the bridges during sync).
 */
final class ProtectedAddresses
{
    /**
     * @return array<string, string> address => reason (English translation key)
     */
    public function for(IpPool $pool): array
    {
        $protected = [];

        if ($pool->gateway !== null) {
            $protected[$pool->gateway] = 'Gateway';
        }

        $network = Cidr::parse($pool->network);

        if ($network->version === 4 && $network->prefix < 31) {
            $first = (int) ip2long($network->address);
            $protected[$network->address] = 'Network address';
            $protected[(string) long2ip($first + 2 ** (32 - $network->prefix) - 1)] = 'Broadcast address';
        }

        foreach ($this->nodeAddresses($pool->location_id) as $address) {
            $protected[$address] = 'Proxmox node';
        }

        return $protected;
    }

    /**
     * IPs configured on node bridges in a location, e.g. "203.0.113.11/24" → "203.0.113.11".
     *
     * @return list<string>
     */
    public function nodeAddresses(int $locationId): array
    {
        $addresses = [];

        $bridges = NetworkBridge::query()
            ->whereNotNull('cidr')
            ->whereHas('node.cluster', fn ($query) => $query->where('location_id', $locationId))
            ->pluck('cidr');

        foreach ($bridges as $cidr) {
            $ip = explode('/', (string) $cidr)[0];

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = (string) inet_ntop((string) inet_pton($ip));
            }
        }

        return array_values(array_unique($addresses));
    }
}
