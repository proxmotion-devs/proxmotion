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
    case SshKeysChanged = 'ssh_keys_changed';

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
            self::SshKeysChanged => 'ssh-keys',
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
            self::SshKeysChanged => 'guest_ssh_keys',
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
            self::SshKeysChanged => ['username', 'ssh_keys', 'removed_ssh_keys'],
        };
    }

    public function hasDefault(): bool
    {
        return $this !== self::Provisioned && $this !== self::PasswordReset;
    }

    /**
     * Whether a failing script fails the task instead of leaving a note: a
     * password nobody knows, or an SSH key that may still let someone in
     * (docs/adr/0049), must not look done.
     */
    public function isCritical(): bool
    {
        return $this === self::PasswordReset || $this === self::SshKeysChanged;
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
