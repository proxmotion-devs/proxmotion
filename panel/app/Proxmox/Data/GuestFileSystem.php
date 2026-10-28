<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One entry of GET /nodes/{node}/qemu/{vmid}/agent/get-fsinfo (the guest agent's
 * guest-get-fsinfo). The sizes are those df shows: total-bytes leaves out the
 * blocks reserved for root, like df's used + available.
 */
final readonly class GuestFileSystem
{
    public function __construct(
        /** The device, e.g. sda1 or dm-0 on Linux, a volume name on Windows. */
        public string $name,
        /** "/" or "/boot" on Linux, "C:\" on Windows. */
        public string $mountpoint,
        public string $type,
        /** Null from agents too old to report sizes. */
        public ?int $usedBytes,
        public ?int $totalBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $int = fn (string $key) => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            mountpoint: is_string($data['mountpoint'] ?? null) ? $data['mountpoint'] : '',
            type: is_string($data['type'] ?? null) ? $data['type'] : '',
            usedBytes: $int('used-bytes'),
            totalBytes: $int('total-bytes'),
        );
    }
}
