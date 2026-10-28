<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One pool of GET /nodes/{node}/disks/zfs (docs/adr/0047).
 */
final readonly class ZfsPoolInfo
{
    public function __construct(
        public string $name,
        /** zpool's health: ONLINE, DEGRADED, FAULTED, OFFLINE, UNAVAIL, REMOVED, SUSPENDED. */
        public string $health,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            health: strtoupper((string) ($data['health'] ?? 'UNKNOWN')),
        );
    }
}
