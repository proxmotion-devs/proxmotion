<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Package;

/**
 * The resources of a new server: taken from a package, or entered by an admin
 * ("custom"). Units: memory and disk in MiB, traffic in bytes, port speed in Mbps.
 */
final readonly class ServerSpec
{
    public function __construct(
        public int $cpuCores,
        public int $memoryMib,
        public int $diskMib,
        public int $ipv4Count,
        public int $ipv6Count,
        public ?int $portSpeedMbps = null,
        public ?int $trafficBytes = null,
        public ResourceLimits $limits = new ResourceLimits,
    ) {}

    public static function fromPackage(Package $package): self
    {
        return new self(
            cpuCores: $package->cpu_cores,
            memoryMib: $package->memory_mib,
            diskMib: $package->disk_mib,
            ipv4Count: $package->ipv4_count,
            ipv6Count: $package->ipv6_count,
            portSpeedMbps: $package->port_speed_mbps,
            trafficBytes: $package->traffic_bytes,
            limits: ResourceLimits::of($package),
        );
    }
}
