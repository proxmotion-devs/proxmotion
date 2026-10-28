<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The health of a storage, a physical disk or a Ceph cluster (docs/adr/0047).
 */
enum StorageHealth: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    /** Not read yet, or Proxmox does not say. */
    case Unknown = 'unknown';

    /**
     * Higher is worse; unknown ranks with ok, so it never counts as getting worse.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Ok, self::Unknown => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * @param  iterable<self>  $healths
     */
    public static function worst(iterable $healths, self $default = self::Ok): self
    {
        $worst = $default;

        foreach ($healths as $health) {
            if ($health->isWorseThan($worst)) {
                $worst = $health;
            }
        }

        return $worst;
    }
}
