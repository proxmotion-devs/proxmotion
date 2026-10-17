<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an OS template offers to app recipes (docs/adr/0016 §3): recipes ask
 * for capabilities instead of naming distributions, so a new template only
 * needs its capabilities set.
 */
enum TemplateCapability: string
{
    case Systemd = 'systemd';
    case Openrc = 'openrc';
    case Apt = 'apt';
    case Dnf = 'dnf';
    case Zypper = 'zypper';
    case Pacman = 'pacman';
    case Apk = 'apk';
    case Bash = 'bash';
    case Powershell = 'powershell';
    case CloudInit = 'cloud-init';
    case Selinux = 'selinux';

    /**
     * Suggested capabilities of a template of this family.
     *
     * @return list<string>
     */
    public static function defaultsFor(OsPlatform $platform, string $family): array
    {
        if ($platform === OsPlatform::Windows) {
            return [self::Powershell->value];
        }

        $capabilities = match ($family) {
            'ubuntu', 'debian' => [self::Systemd, self::Apt, self::Bash, self::CloudInit],
            'almalinux', 'rocky', 'centos', 'fedora' => [self::Systemd, self::Dnf, self::Bash, self::CloudInit, self::Selinux],
            'opensuse' => [self::Systemd, self::Zypper, self::Bash, self::CloudInit],
            'archlinux' => [self::Systemd, self::Pacman, self::Bash, self::CloudInit],
            default => [self::Bash],
        };

        return array_map(fn (self $capability) => $capability->value, $capabilities);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $capability) => $capability->value, self::cases());
    }
}
