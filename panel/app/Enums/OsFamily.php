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

    /**
     * The family an /etc/os-release names first in its ID and ID_LIKE
     * values ("ubuntu debian" → debian); other when none is known.
     *
     * @param  list<string>  $ids
     */
    public static function fromOsReleaseIds(array $ids): self
    {
        foreach ($ids as $id) {
            foreach ([self::Debian, self::Rhel, self::Suse, self::Arch] as $family) {
                if (in_array($id, $family->osReleaseIds(), true)) {
                    return $family;
                }
            }
        }

        return self::Other;
    }

    /**
     * The os-release ID / ID_LIKE values that identify the family inside a
     * server (empty: nothing to check).
     *
     * @return list<string>
     */
    public function osReleaseIds(): array
    {
        return match ($this) {
            self::Debian => ['debian', 'ubuntu'],
            self::Rhel => ['rhel', 'centos', 'fedora', 'almalinux', 'rocky', 'ol'],
            self::Suse => ['suse', 'opensuse'],
            self::Arch => ['arch'],
            self::Windows, self::Other => [],
        };
    }
}
