<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * What GET /nodes/{node}/qemu/{vmid}/status/current says a VM is doing (docs/adr/0036).
 * The network and disk figures are byte counters since the VM process started.
 */
final readonly class VmStats
{
    public function __construct(
        public string $status,
        /** Share of all its vCPUs, 0–1. */
        public ?float $cpu,
        public ?int $cpus,
        public ?int $memoryBytes,
        public ?int $memoryTotalBytes,
        public ?int $netIn,
        public ?int $netOut,
        public ?int $diskRead,
        public ?int $diskWrite,
        public ?int $uptime,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $int = fn (string $key) => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;

        return new self(
            status: is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            cpu: is_numeric($data['cpu'] ?? null) ? (float) $data['cpu'] : null,
            cpus: $int('cpus') ?? $int('maxcpu'),
            memoryBytes: $int('mem'),
            memoryTotalBytes: $int('maxmem'),
            netIn: $int('netin'),
            netOut: $int('netout'),
            diskRead: $int('diskread'),
            diskWrite: $int('diskwrite'),
            uptime: $int('uptime'),
        );
    }

    /**
     * @return array{net_in: int|null, net_out: int|null, disk_read: int|null, disk_write: int|null}
     */
    public function counters(): array
    {
        return [
            'net_in' => $this->netIn,
            'net_out' => $this->netOut,
            'disk_read' => $this->diskRead,
            'disk_write' => $this->diskWrite,
        ];
    }
}
