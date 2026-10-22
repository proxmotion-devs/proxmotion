<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One guest entry of GET /cluster/resources?type=vm. Besides its size, it
 * says what the VM is doing (docs/adr/0039): CPU as a share of its vCPUs
 * (0–1), memory in use, and byte counters since its process started.
 */
final readonly class VmResource
{
    public function __construct(
        public int $vmid,
        public string $node,
        public string $type,
        public ?string $name,
        public bool $template,
        public ?string $status,
        public ?string $pool = null,
        public ?int $cpus = null,
        public ?int $memoryBytes = null,
        public ?int $diskBytes = null,
        public ?float $cpu = null,
        public ?int $memoryUsedBytes = null,
        public ?int $netIn = null,
        public ?int $netOut = null,
        public ?int $diskRead = null,
        public ?int $diskWrite = null,
        public ?int $uptime = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key) => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;
        $int = fn (string $key) => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;

        return new self(
            vmid: (int) $data['vmid'],
            node: (string) $data['node'],
            type: (string) ($data['type'] ?? 'qemu'),
            name: $string('name'),
            template: (bool) ($data['template'] ?? false),
            status: $string('status'),
            pool: $string('pool'),
            cpus: $int('maxcpu'),
            memoryBytes: $int('maxmem'),
            // 0 means "not known yet" (seen on PVE 9.2.2 for a stopped, freshly cloned VM).
            diskBytes: $int('maxdisk') ?: null,
            cpu: is_numeric($data['cpu'] ?? null) ? (float) $data['cpu'] : null,
            memoryUsedBytes: $int('mem'),
            netIn: $int('netin'),
            netOut: $int('netout'),
            diskRead: $int('diskread'),
            diskWrite: $int('diskwrite'),
            uptime: $int('uptime'),
        );
    }
}
