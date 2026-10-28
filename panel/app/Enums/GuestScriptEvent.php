<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When a guest script runs inside a server (docs/adr/0010).
 */
enum GuestScriptEvent: string
{
    case Provisioned = 'provisioned';
    case AddressesChanged = 'addresses_changed';
    case DiskResized = 'disk_resized';
    case PasswordReset = 'password_reset';
    case HostnameChanged = 'hostname_changed';

    /**
     * The file name inside the guest (and of the defaults in resources/guest).
     * "addresses" keeps the path of docs/adr/0009.
     */
    public function name(): string
    {
        return match ($this) {
            self::Provisioned => 'provisioned',
            self::AddressesChanged => 'addresses',
            self::DiskResized => 'disk',
            self::PasswordReset => 'password',
            self::HostnameChanged => 'hostname',
        };
    }

    /** The task step that records the run. */
    public function step(): string
    {
        return match ($this) {
            self::Provisioned => 'guest_provisioned',
            self::AddressesChanged => 'guest_addresses',
            self::DiskResized => 'guest_disk',
            self::PasswordReset => 'set_password',
            self::HostnameChanged => 'guest_hostname',
        };
    }

    /**
     * Input keys besides server, hostname and mac (see GuestScripts::inputs()).
     *
     * @return list<string>
     */
    public function inputKeys(): array
    {
        return match ($this) {
            self::Provisioned => ['reason', 'addresses'],
            self::AddressesChanged => ['addresses', 'primary_ipv4', 'gateway_ipv4', 'cloudinit_ipv4', 'primary_ipv6', 'gateway_ipv6', 'cloudinit_ipv6'],
            self::DiskResized => ['disk_mib'],
            self::PasswordReset => ['username'],
            self::HostnameChanged => ['previous_hostname'],
        };
    }

    public function hasDefault(): bool
    {
        return $this === self::AddressesChanged || $this === self::DiskResized || $this === self::HostnameChanged;
    }

    /** Whether a failing script fails the task instead of leaving a note. */
    public function isCritical(): bool
    {
        return $this === self::PasswordReset;
    }

    /**
     * How long one run may take before the agent's exec gives up on it. The
     * task waits for the script, so longer work belongs in the background.
     */
    public function timeoutSeconds(): int
    {
        return $this->isCritical() ? 60 : 300;
    }

    /** Whether an administrator may run it once on a server to try it. */
    public function canBeTried(): bool
    {
        return $this !== self::PasswordReset;
    }
}
