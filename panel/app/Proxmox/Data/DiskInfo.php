<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One physical disk of GET /nodes/{node}/disks/list (docs/adr/0047).
 */
final readonly class DiskInfo
{
    public function __construct(
        public string $devpath,
        public ?string $model,
        public ?string $serial,
        public ?string $type,
        public ?int $sizeBytes,
        public ?string $used,
        /** smartctl's overall result: PASSED, FAILED, OK (SAS), UNKNOWN… */
        public ?string $smart,
        /** Rated life left in per cent (SSDs and NVMe only); Proxmox's GUI shows 100 minus this as "Wearout". */
        public ?int $wearout,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = static function (mixed $value): ?string {
            $value = is_scalar($value) ? trim((string) $value) : '';

            return $value !== '' ? $value : null;
        };
        // "N/A" for disks without a wear indicator.
        $wearout = $data['wearout'] ?? null;

        return new self(
            devpath: (string) $data['devpath'],
            model: $text($data['model'] ?? null),
            serial: $text($data['serial'] ?? null),
            type: $text($data['type'] ?? null),
            sizeBytes: is_numeric($data['size'] ?? null) ? (int) $data['size'] : null,
            used: $text($data['used'] ?? null),
            smart: ($smart = $text($data['health'] ?? null)) !== null ? strtoupper($smart) : null,
            wearout: is_numeric($wearout) ? max(0, min(100, (int) $wearout)) : null,
        );
    }
}
