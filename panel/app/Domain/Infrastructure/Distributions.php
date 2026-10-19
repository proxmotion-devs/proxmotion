<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\OsFamily;

/**
 * The distributions the panel knows (docs/adr/0024): their os-release ID,
 * display name, family and, for distributions built on another one, the
 * distribution apps are matched as. Distributions outside this list can
 * still be used; administrators then pick the family themselves.
 */
final class Distributions
{
    /**
     * By os-release ID. `base`: the distribution it is compatible with;
     * `same_version`: its versions are the base's (RHEL 9 = AlmaLinux 9);
     * `example`: a version, for the form.
     *
     * @var array<string, array{name: string, family: OsFamily, base?: string, same_version?: bool, example: string}>
     */
    public const array CATALOG = [
        'debian' => ['name' => 'Debian', 'family' => OsFamily::Debian, 'example' => '13'],
        'ubuntu' => ['name' => 'Ubuntu', 'family' => OsFamily::Debian, 'example' => '24.04'],
        'pardus' => ['name' => 'Pardus', 'family' => OsFamily::Debian, 'base' => 'debian', 'example' => '23.4'],
        'linuxmint' => ['name' => 'Linux Mint', 'family' => OsFamily::Debian, 'base' => 'ubuntu', 'example' => '22'],
        'kali' => ['name' => 'Kali Linux', 'family' => OsFamily::Debian, 'base' => 'debian', 'example' => '2025.3'],
        'almalinux' => ['name' => 'AlmaLinux', 'family' => OsFamily::Rhel, 'example' => '9.6'],
        'rocky' => ['name' => 'Rocky Linux', 'family' => OsFamily::Rhel, 'example' => '9.6'],
        'centos' => ['name' => 'CentOS Stream', 'family' => OsFamily::Rhel, 'example' => '10'],
        'fedora' => ['name' => 'Fedora', 'family' => OsFamily::Rhel, 'example' => '42'],
        'rhel' => ['name' => 'Red Hat Enterprise Linux', 'family' => OsFamily::Rhel, 'base' => 'almalinux', 'same_version' => true, 'example' => '9.6'],
        'ol' => ['name' => 'Oracle Linux', 'family' => OsFamily::Rhel, 'base' => 'almalinux', 'same_version' => true, 'example' => '9.6'],
        'opensuse-leap' => ['name' => 'openSUSE Leap', 'family' => OsFamily::Suse, 'example' => '15.6'],
        'opensuse-tumbleweed' => ['name' => 'openSUSE Tumbleweed', 'family' => OsFamily::Suse, 'example' => '20251019'],
        'arch' => ['name' => 'Arch Linux', 'family' => OsFamily::Arch, 'example' => '2025.10.01'],
        'windows' => ['name' => 'Windows', 'family' => OsFamily::Windows, 'example' => '2022'],
    ];

    /**
     * Names used before docs/adr/0024 for distributions that now have
     * their os-release ID (template families, recipe version keys).
     */
    private const array OLD_NAMES = ['archlinux' => 'arch', 'opensuse' => 'opensuse-leap'];

    /** The other way round, for API v1's `family`. */
    private const array API_NAMES = ['arch' => 'archlinux', 'opensuse-leap' => 'opensuse', 'opensuse-tumbleweed' => 'opensuse'];

    public static function known(string $distribution): bool
    {
        return isset(self::CATALOG[$distribution]);
    }

    /** The family of a known distribution; null when the catalog does not know it. */
    public static function family(string $distribution): ?OsFamily
    {
        return self::CATALOG[$distribution]['family'] ?? null;
    }

    public static function name(string $distribution): string
    {
        return self::CATALOG[$distribution]['name'] ?? $distribution;
    }

    /** The distribution a known derivative is matched as (pardus → debian). */
    public static function base(string $distribution): ?string
    {
        return self::CATALOG[$distribution]['base'] ?? null;
    }

    /** Whether a known derivative has the versions of its base (RHEL, Oracle Linux). */
    public static function sameVersion(string $distribution): bool
    {
        return self::CATALOG[$distribution]['same_version'] ?? false;
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
            self::CATALOG,
            fn (array $entry) => ! isset($entry['base']) && $entry['family'] !== OsFamily::Windows,
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
        return array_values(array_filter(self::bases(), fn (string $id) => self::CATALOG[$id]['family'] === $family));
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
     * The catalog for the admin form.
     *
     * @return list<array{id: string, name: string, family: string, base: string|null, same_version: bool, example: string}>
     */
    public static function forForm(): array
    {
        $list = [];

        foreach (self::CATALOG as $id => $entry) {
            $list[] = [
                'id' => $id,
                'name' => $entry['name'],
                'family' => $entry['family']->value,
                'base' => $entry['base'] ?? null,
                'same_version' => $entry['same_version'] ?? false,
                'example' => $entry['example'],
            ];
        }

        return $list;
    }
}
