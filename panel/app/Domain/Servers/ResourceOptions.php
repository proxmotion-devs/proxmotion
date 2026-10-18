<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\IpVersion;
use App\Models\Package;
use App\Models\Server;

/**
 * What the "Change resources" dialog offers: the packages that fit the
 * server's network model (with their values, to fill the form) and the
 * server's current resources.
 */
final class ResourceOptions
{
    /**
     * A package's resources in the units of the resource forms (disk in GiB,
     * traffic in GB; null port speed or traffic is unlimited).
     *
     * @return array{cpu_cores: int, memory_mib: int, disk_gib: int, ipv4_count: int, ipv6_count: int, port_speed_mbps: int|null, traffic_gb: int|null}
     */
    public static function packageValues(Package $package): array
    {
        return [
            'cpu_cores' => $package->cpu_cores,
            'memory_mib' => $package->memory_mib,
            'disk_gib' => intdiv($package->disk_mib, 1024),
            'ipv4_count' => $package->ipv4_count,
            'ipv6_count' => $package->ipv6_count,
            'port_speed_mbps' => $package->port_speed_mbps,
            'traffic_gb' => $package->traffic_bytes === null ? null : intdiv($package->traffic_bytes, 1000 ** 3),
        ];
    }

    /**
     * @return array{packages: list<array<string, mixed>>, current: array<string, mixed>}
     */
    public static function for(Server $server): array
    {
        $packages = Package::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Package $package) => $package->network_models->contains($server->network_model))
            ->map(fn (Package $package) => [
                'value' => $package->uuid,
                'label' => $package->name,
                ...self::packageValues($package),
            ]);

        return [
            'packages' => array_values($packages->all()),
            'current' => [
                // Looked up by id: pages load the server's package with only a few columns.
                'package' => $server->package_id === null ? '' : (string) Package::query()->whereKey($server->package_id)->value('uuid'),
                'cpu_cores' => $server->cpu_cores,
                'memory_mib' => $server->memory_mib,
                'disk_gib' => intdiv($server->disk_mib, 1024),
                'ipv4_count' => $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', IpVersion::V4))->count(),
                'ipv6_count' => $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', IpVersion::V6))->count(),
                'port_speed_mbps' => $server->port_speed_mbps,
                'traffic_gb' => $server->traffic_bytes === null ? null : intdiv($server->traffic_bytes, 1000 ** 3),
            ],
        ];
    }
}
