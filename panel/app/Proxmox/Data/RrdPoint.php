<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One entry of GET /nodes/{node}/qemu/{vmid}/rrddata: averages over its interval,
 * network and disk in bytes per second. Intervals without data have no figures.
 */
final readonly class RrdPoint
{
    public function __construct(
        public int $time,
        /** Share of all its vCPUs, 0–1. */
        public ?float $cpu,
        public ?float $memoryBytes,
        public ?float $netIn,
        public ?float $netOut,
        public ?float $diskRead,
        public ?float $diskWrite,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $float = fn (string $key) => is_numeric($data[$key] ?? null) ? (float) $data[$key] : null;

        return new self(
            time: is_numeric($data['time'] ?? null) ? (int) $data['time'] : 0,
            cpu: $float('cpu'),
            memoryBytes: $float('mem'),
            netIn: $float('netin'),
            netOut: $float('netout'),
            diskRead: $float('diskread'),
            diskWrite: $float('diskwrite'),
        );
    }
}
