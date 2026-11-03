<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One backup of GET /nodes/{node}/storage/{storage}/content?content=backup (docs/adr/0051 §4).
 */
final readonly class BackupVolume
{
    public function __construct(
        public string $volume,
        public ?int $vmid,
        public ?int $sizeBytes,
        /** Unix time it was written. */
        public ?int $createdAt,
        public string $notes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            volume: (string) $data['volid'],
            vmid: is_numeric($data['vmid'] ?? null) ? (int) $data['vmid'] : null,
            sizeBytes: is_numeric($data['size'] ?? null) ? (int) $data['size'] : null,
            createdAt: is_numeric($data['ctime'] ?? null) ? (int) $data['ctime'] : null,
            notes: is_string($data['notes'] ?? null) ? $data['notes'] : '',
        );
    }
}
