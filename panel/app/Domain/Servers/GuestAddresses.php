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
     * The primary address of each version with its gateway, as guest script
     * inputs: primary_ipv4, gateway_ipv4, primary_ipv6, gateway_ipv6 (null
     * when the server has none).
     *
     * @return array<string, string|null>
     */
    public function primaries(Server $server): array
    {
        $inputs = [];

        foreach ([IpVersion::V4, IpVersion::V6] as $version) {
            $primary = $server->ipAddresses()->with('pool')->whereHas('pool', fn ($query) => $query->where('version', $version))->first();
            $suffix = $version === IpVersion::V4 ? 'ipv4' : 'ipv6';

            $inputs['primary_'.$suffix] = $primary === null ? null : $primary->address.'/'.$this->prefix($server, $primary, $version);
            $inputs['gateway_'.$suffix] = $primary?->pool->gateway;
        }

        return $inputs;
    }

    /**
     * The addresses cloud-init configures from the VM's ipconfig0, which the
     * panel never changes on an existing server (docs/adr/0011): cloudinit_ipv4
     * and cloudinit_ipv6, as "address/prefix".
     *
     * @param  array<string, mixed>  $config  the VM's configuration
     * @return array<string, string|null>
     */
    public static function cloudInit(array $config): array
    {
        $ipconfig = is_string($config['ipconfig0'] ?? null) ? $config['ipconfig0'] : '';
        $inputs = ['cloudinit_ipv4' => null, 'cloudinit_ipv6' => null];

        foreach (explode(',', $ipconfig) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 'ip' && str_contains($value, '/')) {
                $inputs['cloudinit_ipv4'] = $value;
            } elseif ($key === 'ip6' && str_contains($value, '/')) {
                $inputs['cloudinit_ipv6'] = $value;
            }
        }

        return $inputs;
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
