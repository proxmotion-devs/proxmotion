<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * Which operating systems a recipe runs on (docs/adr/0016 §3). Recipes name
 * OS template families (ubuntu, rocky, …) or groups of related families,
 * the way /etc/os-release does with ID and ID_LIKE: "debian" covers Debian
 * and Ubuntu, "rhel" RHEL, AlmaLinux, Rocky, CentOS and Fedora. Templates
 * need nothing besides their family; the runner checks /etc/os-release
 * again inside the server.
 */
final class OsFamilies
{
    /**
     * Groups by name: the template families they cover and the os-release
     * ID / ID_LIKE values that identify them inside a server.
     *
     * @var array<string, array{families: list<string>, os_release: list<string>}>
     */
    public const GROUPS = [
        'debian' => ['families' => ['debian', 'ubuntu'], 'os_release' => ['debian', 'ubuntu']],
        'rhel' => ['families' => ['almalinux', 'rocky', 'centos', 'fedora'], 'os_release' => ['rhel', 'centos', 'fedora', 'almalinux', 'rocky']],
        'suse' => ['families' => ['opensuse'], 'os_release' => ['suse', 'opensuse']],
        'arch' => ['families' => ['archlinux'], 'os_release' => ['arch']],
    ];

    /**
     * os-release IDs of single template families (a family named in a
     * recipe that is not a group).
     *
     * @var array<string, list<string>>
     */
    public const FAMILY_OS_RELEASE = [
        'ubuntu' => ['ubuntu'],
        'almalinux' => ['almalinux'],
        'rocky' => ['rocky'],
        'centos' => ['centos'],
        'fedora' => ['fedora'],
        'opensuse' => ['opensuse-leap', 'opensuse-tumbleweed', 'opensuse'],
        'archlinux' => ['arch'],
    ];

    /** Package managers of recipes written before families (format 2 `capabilities`). */
    private const LEGACY_CAPABILITIES = ['apt' => 'debian', 'dnf' => 'rhel', 'zypper' => 'suse', 'pacman' => 'arch'];

    /**
     * Whether a template family is one of the wanted families or groups.
     *
     * @param  list<string>  $wanted
     */
    public static function matches(array $wanted, string $family): bool
    {
        $family = strtolower($family);

        foreach ($wanted as $name) {
            if ($name === $family || in_array($family, self::GROUPS[$name]['families'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The os-release ID / ID_LIKE values the runner accepts inside the
     * server for the wanted families and groups; empty: any.
     *
     * @param  list<string>|null  $wanted
     * @return list<string>
     */
    public static function osReleaseIds(?array $wanted): array
    {
        $ids = [];

        foreach ($wanted ?? [] as $name) {
            $ids = [...$ids, ...(self::GROUPS[$name]['os_release'] ?? self::FAMILY_OS_RELEASE[$name] ?? [$name])];
        }

        return array_values(array_unique($ids));
    }

    /**
     * The groups a format 2 recipe asked for through package-manager
     * capabilities ("apt|dnf" → debian, rhel); null when none names one.
     *
     * @param  list<string>  $capabilities
     * @return list<string>|null
     */
    public static function fromCapabilities(array $capabilities): ?array
    {
        $groups = [];

        foreach ($capabilities as $capability) {
            foreach (explode('|', $capability) as $name) {
                if (isset(self::LEGACY_CAPABILITIES[$name])) {
                    $groups[] = self::LEGACY_CAPABILITIES[$name];
                }
            }
        }

        return $groups === [] ? null : array_values(array_unique($groups));
    }

    /**
     * The template families the names cover (groups expanded).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function expand(array $names): array
    {
        $families = [];

        foreach ($names as $name) {
            $families = [...$families, ...(self::GROUPS[$name]['families'] ?? [$name])];
        }

        return array_values(array_unique($families));
    }

    /**
     * Group names, for the editor.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return array_keys(self::GROUPS);
    }
}
