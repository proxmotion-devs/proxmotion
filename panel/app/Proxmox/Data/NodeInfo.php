<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One entry of GET /nodes.
 */
final readonly class NodeInfo
{
    public function __construct(
        public string $name,
        public bool $online,
        public ?int $cpuCores,
        public ?int $memoryBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['node'],
            online: ($data['status'] ?? null) === 'online',
            cpuCores: isset($data['maxcpu']) ? (int) $data['maxcpu'] : null,
            memoryBytes: isset($data['maxmem']) ? (int) $data['maxmem'] : null,
        );
    }
}
