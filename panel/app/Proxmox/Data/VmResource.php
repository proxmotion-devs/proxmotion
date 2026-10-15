<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One guest entry of GET /cluster/resources?type=vm.
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
            diskBytes: $int('maxdisk'),
        );
    }
}
