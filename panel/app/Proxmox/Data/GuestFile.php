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
     * Proxmox sends the file's bytes as a JSON string with one character per
     * byte (U+0000–U+00FF), so after json_decode every byte from 0x80 up is
     * two bytes of UTF-8. Converting back to Latin-1 restores the file as it is.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $content = is_string($data['content'] ?? null) ? $data['content'] : '';

        return new self(
            content: mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'),
            truncated: (bool) ($data['truncated'] ?? false),
        );
    }
}
