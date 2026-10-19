<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\OsFamily;

/**
 * The distributions the panel knows: their os-release ID, name, family,
 * releases and, for distributions built on another one, the distribution
 * apps are matched as (docs/adr/0024). Administrators manage them on the
 * OS templates page (docs/adr/0025); this reads them through
 * DistributionCatalog. Distributions outside the list can still be used as
 * *Other…*.
 */
final class Distributions
{
    /**
     * Names used before docs/adr/0024 for distributions that now have
     * their os-release ID (template families, recipe version keys).
     */
    private const array OLD_NAMES = ['archlinux' => 'arch', 'opensuse' => 'opensuse-leap'];

    /** Families of well-known os-release IDs, whatever administrators list (recipeFamily()). */
    private const array BUILT_IN_FAMILIES = [
        'debian' => OsFamily::Debian, 'ubuntu' => OsFamily::Debian, 'pardus' => OsFamily::Debian,
        'linuxmint' => OsFamily::Debian, 'kali' => OsFamily::Debian,
        'rhel' => OsFamily::Rhel, 'almalinux' => OsFamily::Rhel, 'rocky' => OsFamily::Rhel,
        'centos' => OsFamily::Rhel, 'fedora' => OsFamily::Rhel, 'ol' => OsFamily::Rhel,
        'opensuse-leap' => OsFamily::Suse, 'opensuse-tumbleweed' => OsFamily::Suse,
        'arch' => OsFamily::Arch, 'windows' => OsFamily::Windows,
    ];

    /** The other way round, for API v1's `family`. */
    private const array API_NAMES = ['arch' => 'archlinux', 'opensuse-leap' => 'opensuse', 'opensuse-tumbleweed' => 'opensuse'];

    /**
     * @return array<string, array{name: string, family: OsFamily, base: string|null, same_version: bool, sort_order: int, releases: list<array{id: int, version: string, base_version: string|null}>}>
     */
    private static function catalog(): array
    {
        return app(DistributionCatalog::class)->all();
    }

    public static function known(string $distribution): bool
    {
        return isset(self::catalog()[$distribution]);
    }

    /**
     * The family of a distribution for recipes: fixed for well-known
     * os-release IDs, so bundled recipes validate the same everywhere (also
     * without a database), else as administrators listed it.
     */
    public static function recipeFamily(string $distribution): ?OsFamily
    {
        return self::BUILT_IN_FAMILIES[$distribution] ?? self::family($distribution);
    }

    /** The family of a known distribution; null when the catalog does not know it. */
    public static function family(string $distribution): ?OsFamily
    {
        return self::catalog()[$distribution]['family'] ?? null;
    }

    public static function name(string $distribution): string
    {
        return self::catalog()[$distribution]['name'] ?? $distribution;
    }

    /** The distribution a known derivative is matched as (pardus → debian). */
    public static function base(string $distribution): ?string
    {
        return self::catalog()[$distribution]['base'] ?? null;
    }

    /** Whether a known derivative has the versions of its base (RHEL, Oracle Linux). */
    public static function sameVersion(string $distribution): bool
    {
        return self::catalog()[$distribution]['same_version'] ?? false;
    }

    /**
     * The listed release a version belongs to: itself, the release it is a
     * point release of (23 for 23.4) or "rolling"; null when none covers it.
     */
    public static function releaseOf(string $distribution, string $version): ?string
    {
        foreach (self::catalog()[$distribution]['releases'] ?? [] as $release) {
            $listed = $release['version'];

            if ($listed === 'rolling' || $version === $listed || str_starts_with($version, $listed.'.')) {
                return $listed;
            }
        }

        return null;
    }

    /**
     * The version of its base a known derivative's version is matched as:
     * the same version for RHEL and Oracle Linux, its release's base version
     * for the others (Pardus 23.4 → Debian 12); null when no release covers it.
     */
    public static function baseVersion(string $distribution, string $version): ?string
    {
        if (self::base($distribution) === null) {
            return null;
        }

        if (self::sameVersion($distribution)) {
            return $version === '' ? null : $version;
        }

        $listed = self::releaseOf($distribution, $version);

        foreach (self::catalog()[$distribution]['releases'] as $release) {
            if ($release['version'] === $listed) {
                return $release['base_version'];
            }
        }

        return null;
    }

    /**
     * Distributions apps can be matched as: known, not built on another
     * one and not Windows.
     *
     * @return list<string>
     */
    public static function bases(): array
    {
        return array_keys(array_filter(
            self::catalog(),
            fn (array $entry) => $entry['base'] === null && $entry['family'] !== OsFamily::Windows,
        ));
    }

    /**
     * The base distributions of a family (those a recipe naming the
     * family covers by name).
     *
     * @return list<string>
     */
    public static function ofFamily(OsFamily $family): array
    {
        return array_values(array_filter(self::bases(), fn (string $id) => self::catalog()[$id]['family'] === $family));
    }

    /** A distribution name from before docs/adr/0024 (archlinux) as its os-release ID (arch). */
    public static function fromOldName(string $name): string
    {
        $name = strtolower($name);

        return self::OLD_NAMES[$name] ?? $name;
    }

    /** The name API v1's `family` used for a distribution (arch → archlinux). */
    public static function apiName(string $distribution): string
    {
        return self::API_NAMES[$distribution] ?? $distribution;
    }

    /**
     * The catalog for the admin pages.
     *
     * @return list<array{id: string, name: string, family: string, base: string|null, same_version: bool, releases: list<array{id: int, version: string, base_version: string|null}>}>
     */
    public static function forForm(): array
    {
        $list = [];

        foreach (self::catalog() as $id => $entry) {
            $list[] = [
                'id' => $id,
                'name' => $entry['name'],
                'family' => $entry['family']->value,
                'base' => $entry['base'],
                'same_version' => $entry['same_version'],
                'releases' => $entry['releases'],
            ];
        }

        return $list;
    }
}
