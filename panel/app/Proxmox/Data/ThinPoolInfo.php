<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One LVM thin pool of GET /nodes/{node}/disks/lvmthin (docs/adr/0047).
 */
final readonly class ThinPoolInfo
{
    public function __construct(
        public string $name,
        public string $volumeGroup,
        public ?int $metadataUsedBytes,
        public ?int $metadataTotalBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['lv'],
            volumeGroup: (string) ($data['vg'] ?? ''),
            metadataUsedBytes: is_numeric($data['metadata_used'] ?? null) ? (int) $data['metadata_used'] : null,
            metadataTotalBytes: is_numeric($data['metadata_size'] ?? null) ? (int) $data['metadata_size'] : null,
        );
    }
}
