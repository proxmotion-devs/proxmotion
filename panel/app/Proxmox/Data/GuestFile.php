<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * GET /nodes/{node}/qemu/{vmid}/agent/file-read (decoded content).
 */
final readonly class GuestFile
{
    public function __construct(
        public string $content,
        /** The read stopped before the end of the file (Proxmox reads at most 16 MiB). */
        public bool $truncated,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        return new self(
            content: is_string($data['content'] ?? null) ? $data['content'] : '',
            truncated: (bool) ($data['truncated'] ?? false),
        );
    }
}
