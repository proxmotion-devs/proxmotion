<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One entry of GET /nodes/{node}/network?type=any_bridge.
 */
final readonly class BridgeInfo
{
    public function __construct(
        public string $name,
        public bool $active,
        public bool $vlanAware,
        public ?string $cidr,
        public ?string $comments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $cidr = $data['cidr'] ?? null;
        $comments = isset($data['comments']) ? trim((string) $data['comments']) : null;

        return new self(
            name: (string) $data['iface'],
            active: (bool) ($data['active'] ?? false),
            vlanAware: (bool) ($data['bridge_vlan_aware'] ?? false),
            cidr: is_string($cidr) && $cidr !== '' ? $cidr : null,
            comments: $comments !== '' ? $comments : null,
        );
    }
}
