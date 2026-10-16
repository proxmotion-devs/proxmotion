<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\Server;

/**
 * A server's addresses as guest scripts get them (docs/adr/0009, 0010):
 * "address/prefix", routed IPv4 addresses as /32.
 */
final readonly class GuestAddresses
{
    /**
     * Every address of the server, the primary ones first.
     *
     * @return list<string>
     */
    public function all(Server $server): array
    {
        return array_merge(...array_values($this->byVersion($server)));
    }

    /**
     * The addresses cloud-init does not configure: all but the first of each
     * IP version.
     *
     * @return list<string>
     */
    public function additional(Server $server): array
    {
        return array_merge(...array_map(fn (array $lines) => array_slice($lines, 1), array_values($this->byVersion($server))));
    }

    /**
     * @return array<int, list<string>>
     */
    private function byVersion(Server $server): array
    {
        $lines = [4 => [], 6 => []];

        foreach ($server->ipAddresses()->with('pool')->orderBy('id')->get() as $address) {
            $version = $address->pool->version;
            $lines[$version->value][] = $address->address.'/'.$this->prefix($server, $address, $version);
        }

        return $lines;
    }

    private function prefix(Server $server, IpAddress $address, IpVersion $version): int
    {
        if ($version === IpVersion::V4 && $server->network_model === NetworkModel::Routed) {
            return 32;
        }

        $parts = explode('/', $address->pool->network);

        return (int) ($parts[1] ?? ($version === IpVersion::V4 ? 32 : 128));
    }
}
