<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

/**
 * Guesses the operating system of a Proxmox VM template from its name
 * (e.g. "ubuntu-2404-cloud", "debian-12", "alma9", "win2022", "pardus-23"),
 * so an OS template can be added with one click. Administrators check the
 * guess before saving; nothing is stored without them.
 *
 * Distributions built on another one (docs/adr/0024) get that one as
 * compatible distribution, with the version the catalog matches it as
 * (RHEL 9 is AlmaLinux 9, Pardus 23 is Debian 12).
 */
final class TemplateNameGuess
{
    /**
     * Name fragments in the order they are looked for (distributions built
     * on another one first, longer names before their short forms), with
     * the os-release ID they stand for.
     */
    private const array FRAGMENTS = [
        'pardus' => 'pardus',
        'linuxmint' => 'linuxmint',
        'mint' => 'linuxmint',
        'kali' => 'kali',
        'oraclelinux' => 'ol',
        'oracle' => 'ol',
        'rhel' => 'rhel',
        'ubuntu' => 'ubuntu',
        'debian' => 'debian',
        'almalinux' => 'almalinux',
        'alma' => 'almalinux',
        'rockylinux' => 'rocky',
        'rocky' => 'rocky',
        'centos' => 'centos',
        'fedora' => 'fedora',
        'tumbleweed' => 'opensuse-tumbleweed',
        'opensuse' => 'opensuse-leap',
        'suse' => 'opensuse-leap',
        'archlinux' => 'arch',
        'arch' => 'arch',
        'windows' => 'windows',
        'win' => 'windows',
    ];

    /**
     * @return array{name: string, distribution: string, version: string, compatible_with: string|null, compatible_version: string|null}
     */
    public static function fromName(?string $templateName): array
    {
        $raw = trim((string) $templateName);
        $lower = strtolower($raw);

        // Distributions administrators added (docs/adr/0025) by their os-release ID, longest first.
        $added = array_diff(array_column(Distributions::forForm(), 'id'), self::FRAGMENTS);
        usort($added, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ([...self::FRAGMENTS, ...array_combine($added, $added)] as $fragment => $distribution) {
            $position = strpos($lower, $fragment);

            if ($position === false) {
                continue;
            }

            $version = self::version($distribution, substr($lower, $position + strlen($fragment)));
            $base = Distributions::base($distribution);

            return [
                'name' => self::displayName($distribution, $version),
                'distribution' => $distribution,
                'version' => $version,
                'compatible_with' => $base,
                'compatible_version' => $version === '' ? null : Distributions::baseVersion($distribution, $version),
            ];
        }

        // Unknown: the administrator gives the os-release ID and version.
        return ['name' => $raw, 'distribution' => '', 'version' => self::version('', $lower), 'compatible_with' => null, 'compatible_version' => null];
    }

    /**
     * The first number after the name: "2404" and "24-04" become "24.04"
     * for Ubuntu, "12.7" stays, "9" stays.
     */
    private static function version(string $distribution, string $rest): string
    {
        if (preg_match('/(\d{1,4})(?:[._-](\d{1,2})(?!\d))?/', $rest, $match) !== 1) {
            return '';
        }

        $major = $match[1];
        $minor = $match[2] ?? null;

        if ($distribution === 'ubuntu' && $minor === null && strlen($major) === 4) {
            return substr($major, 0, 2).'.'.substr($major, 2);
        }

        if ($distribution === 'ubuntu' && $minor !== null) {
            return $major.'.'.str_pad($minor, 2, '0', STR_PAD_LEFT);
        }

        return $minor === null ? $major : $major.'.'.$minor;
    }

    private static function displayName(string $distribution, string $version): string
    {
        if ($distribution === 'windows') {
            $name = strlen($version) === 4 ? 'Windows Server' : 'Windows';
        } else {
            $name = Distributions::name($distribution);
        }

        if ($distribution === 'ubuntu' && preg_match('/^\d\d\.04$/', $version) === 1 && (int) $version % 2 === 0) {
            return "{$name} {$version} LTS";
        }

        return trim("{$name} {$version}");
    }
}
