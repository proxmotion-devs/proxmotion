<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * The disk a VM boots from, read from its config (GET .../qemu/{vmid}/config).
 */
final readonly class BootDisk
{
    private const DISK_KEYS = ['scsi0', 'virtio0', 'sata0', 'ide0'];

    public function __construct(
        /** The config key, e.g. "scsi0". */
        public string $key,
        public int $sizeMib,
    ) {}

    /**
     * The first disk of the boot order (then scsi0, virtio0, sata0, ide0) that
     * is not a CD-ROM and has a size, or null if there is none.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): ?self
    {
        $candidates = self::DISK_KEYS;

        if (is_string($config['boot'] ?? null) && preg_match('/order=([^,]+)/', $config['boot'], $match) === 1) {
            $candidates = [...explode(';', $match[1]), ...$candidates];
        }

        foreach ($candidates as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && ! str_contains($value, 'media=cdrom') && preg_match('/size=([\d.]+)([KMGT]?)/', $value, $size) === 1) {
                return new self($key, self::toMib((float) $size[1], $size[2]));
            }
        }

        return null;
    }

    private static function toMib(float $value, string $unit): int
    {
        return (int) round(match ($unit) {
            'K' => $value / 1024,
            'G' => $value * 1024,
            'T' => $value * 1024 * 1024,
            'M' => $value,
            default => $value / 1024 / 1024, // bytes
        });
    }
}
