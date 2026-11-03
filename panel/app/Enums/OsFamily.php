<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operating system families: distributions that share packages and tools,
 * like ID_LIKE in /etc/os-release (docs/adr/0024). Which distribution
 * belongs to which family is in App\Domain\Infrastructure\Distributions.
 */
enum OsFamily: string
{
    case Debian = 'debian';
    case Rhel = 'rhel';
    case Suse = 'suse';
    case Arch = 'arch';
    case Windows = 'windows';
    case Other = 'other';

    public function platform(): OsPlatform
    {
        return $this === self::Windows ? OsPlatform::Windows : OsPlatform::Linux;
    }

    /** English display name (a translation key). */
    public function label(): string
    {
        return match ($this) {
            self::Debian => 'Debian family',
            self::Rhel => 'RHEL family',
            self::Suse => 'SUSE family',
            self::Arch => 'Arch family',
            self::Windows => 'Windows',
            self::Other => 'Other',
        };
    }
}
