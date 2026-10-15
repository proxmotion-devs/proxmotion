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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? null;
        $status = $data['status'] ?? null;

        return new self(
            vmid: (int) $data['vmid'],
            node: (string) $data['node'],
            type: (string) ($data['type'] ?? 'qemu'),
            name: is_string($name) ? $name : null,
            template: (bool) ($data['template'] ?? false),
            status: is_string($status) ? $status : null,
        );
    }
}
