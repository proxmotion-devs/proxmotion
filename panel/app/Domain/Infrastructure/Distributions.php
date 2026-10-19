<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\OsFamily;

/**
 * What the panel itself knows about well-known os-release IDs: their names
 * and families. Recipes name families and IDs in `requires.os`, which only
 * suggests the systems an app fits (docs/adr/0026); administrators decide.
 * Nothing here limits the operating systems administrators add.
 */
final class Distributions
{
    /**
     * By os-release ID.
     *
     * @var array<string, array{name: string, family: OsFamily}>
     */
    private const array KNOWN = [
        'debian' => ['name' => 'Debian', 'family' => OsFamily::Debian],
        'ubuntu' => ['name' => 'Ubuntu', 'family' => OsFamily::Debian],
        'pardus' => ['name' => 'Pardus', 'family' => OsFamily::Debian],
        'linuxmint' => ['name' => 'Linux Mint', 'family' => OsFamily::Debian],
        'kali' => ['name' => 'Kali Linux', 'family' => OsFamily::Debian],
        'almalinux' => ['name' => 'AlmaLinux', 'family' => OsFamily::Rhel],
        'rocky' => ['name' => 'Rocky Linux', 'family' => OsFamily::Rhel],
        'centos' => ['name' => 'CentOS Stream', 'family' => OsFamily::Rhel],
        'fedora' => ['name' => 'Fedora', 'family' => OsFamily::Rhel],
        'rhel' => ['name' => 'Red Hat Enterprise Linux', 'family' => OsFamily::Rhel],
        'ol' => ['name' => 'Oracle Linux', 'family' => OsFamily::Rhel],
        'opensuse-leap' => ['name' => 'openSUSE Leap', 'family' => OsFamily::Suse],
        'opensuse-tumbleweed' => ['name' => 'openSUSE Tumbleweed', 'family' => OsFamily::Suse],
        'arch' => ['name' => 'Arch Linux', 'family' => OsFamily::Arch],
        'windows' => ['name' => 'Windows', 'family' => OsFamily::Windows],
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
        return isset(self::KNOWN[$distribution]);
    }

    /** The family of a well-known os-release ID; null for any other. */
    public static function family(string $distribution): ?OsFamily
    {
        return self::KNOWN[$distribution]['family'] ?? null;
    }

    public static function name(string $distribution): string
    {
        return self::KNOWN[$distribution]['name'] ?? ucfirst($distribution);
    }

    /**
     * The well-known IDs of a family (those a recipe naming the family covers by name).
     *
     * @return list<string>
     */
    public static function ofFamily(OsFamily $family): array
    {
        return array_keys(array_filter(self::KNOWN, fn (array $entry) => $entry['family'] === $family && $family !== OsFamily::Windows));
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
     * The well-known IDs for the recipe editor's OS picker.
     *
     * @return list<array{id: string, name: string, family: string, base: null}>
     */
    public static function forForm(): array
    {
        $list = [];

        foreach (self::KNOWN as $id => $entry) {
            $list[] = ['id' => $id, 'name' => $entry['name'], 'family' => $entry['family']->value, 'base' => null];
        }

        return $list;
    }
}
