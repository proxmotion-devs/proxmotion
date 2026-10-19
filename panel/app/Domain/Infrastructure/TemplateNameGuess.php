<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * Guesses the operating system of a Proxmox VM template from its name
 * (e.g. "ubuntu-2404-cloud", "debian-12", "alma9", "win2022", "pardus-23"),
 * so an OS template can be added with one click. Administrators check the
 * guess before saving; nothing is stored without them.
 *
 * Derivatives (docs/adr/0022) get their own os-release ID as distribution
 * and the system they are based on as family. Their base version is only
 * guessed where it is the same number (RHEL and Oracle Linux 9 are
 * AlmaLinux 9); otherwise the administrator enters it.
 */
final class TemplateNameGuess
{
    /**
     * Name fragments of derivatives, looked for first: os-release ID, base
     * family, display name and whether the version is the base's.
     *
     * @var array<string, array{string, string, string, bool}>
     */
    private const array DERIVATIVES = [
        'pardus' => ['pardus', 'debian', 'Pardus', false],
        'linuxmint' => ['linuxmint', 'ubuntu', 'Linux Mint', false],
        'mint' => ['linuxmint', 'ubuntu', 'Linux Mint', false],
        'kali' => ['kali', 'debian', 'Kali Linux', false],
        'oraclelinux' => ['ol', 'almalinux', 'Oracle Linux', true],
        'oracle' => ['ol', 'almalinux', 'Oracle Linux', true],
        'rhel' => ['rhel', 'almalinux', 'Red Hat Enterprise Linux', true],
    ];

    /** Name fragments in the order they are looked for, with their family. */
    private const array FAMILIES = [
        'ubuntu' => 'ubuntu',
        'debian' => 'debian',
        'almalinux' => 'almalinux',
        'alma' => 'almalinux',
        'rockylinux' => 'rocky',
        'rocky' => 'rocky',
        'centos' => 'centos',
        'fedora' => 'fedora',
        'opensuse' => 'opensuse',
        'suse' => 'opensuse',
        'archlinux' => 'archlinux',
        'arch' => 'archlinux',
        'windows' => 'windows',
        'win' => 'windows',
    ];

    private const array NAMES = [
        'ubuntu' => 'Ubuntu',
        'debian' => 'Debian',
        'almalinux' => 'AlmaLinux',
        'rocky' => 'Rocky Linux',
        'centos' => 'CentOS Stream',
        'fedora' => 'Fedora',
        'opensuse' => 'openSUSE',
        'archlinux' => 'Arch Linux',
    ];

    /**
     * @return array{name: string, family: string, version: string, distribution: string|null, distribution_version: string|null}
     */
    public static function fromName(?string $templateName): array
    {
        $raw = trim((string) $templateName);
        $lower = strtolower($raw);

        foreach (self::DERIVATIVES as $fragment => [$distribution, $family, $name, $sameVersion]) {
            $position = strpos($lower, $fragment);

            if ($position === false) {
                continue;
            }

            $version = self::version('other', substr($lower, $position + strlen($fragment)));

            return [
                'name' => trim("{$name} {$version}"),
                'family' => $family,
                'version' => $sameVersion ? $version : '',
                'distribution' => $distribution,
                'distribution_version' => $version === '' ? null : $version,
            ];
        }

        foreach (self::FAMILIES as $fragment => $family) {
            $position = strpos($lower, $fragment);

            if ($position === false) {
                continue;
            }

            $version = self::version($family, substr($lower, $position + strlen($fragment)));

            return ['name' => self::displayName($family, $version), 'family' => $family, 'version' => $version, 'distribution' => null, 'distribution_version' => null];
        }

        return ['name' => $raw, 'family' => 'other', 'version' => self::version('other', $lower), 'distribution' => null, 'distribution_version' => null];
    }

    /**
     * The first number after the family: "2404" and "24-04" become "24.04"
     * for Ubuntu, "12.7" stays, "9" stays.
     */
    private static function version(string $family, string $rest): string
    {
        if (preg_match('/(\d{1,4})(?:[._-](\d{1,2})(?!\d))?/', $rest, $match) !== 1) {
            return '';
        }

        $major = $match[1];
        $minor = $match[2] ?? null;

        if ($family === 'ubuntu' && $minor === null && strlen($major) === 4) {
            return substr($major, 0, 2).'.'.substr($major, 2);
        }

        if ($family === 'ubuntu' && $minor !== null) {
            return $major.'.'.str_pad($minor, 2, '0', STR_PAD_LEFT);
        }

        return $minor === null ? $major : $major.'.'.$minor;
    }

    private static function displayName(string $family, string $version): string
    {
        if ($family === 'windows') {
            $name = strlen($version) === 4 ? 'Windows Server' : 'Windows';
        } else {
            $name = self::NAMES[$family];
        }

        if ($family === 'ubuntu' && preg_match('/^\d\d\.04$/', $version) === 1 && (int) $version % 2 === 0) {
            return "{$name} {$version} LTS";
        }

        return trim("{$name} {$version}");
    }
}
