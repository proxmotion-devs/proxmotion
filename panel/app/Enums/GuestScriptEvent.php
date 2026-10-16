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
            self::Provisioned, self::AddressesChanged => ['addresses'],
            self::DiskResized => ['disk_mib'],
            self::PasswordReset => ['username'],
        };
    }

    public function hasDefault(): bool
    {
        return $this === self::AddressesChanged || $this === self::DiskResized;
    }

    /** Whether a failing script fails the task instead of leaving a note. */
    public function isCritical(): bool
    {
        return $this === self::PasswordReset;
    }

    /** Whether an administrator may run it once on a server to try it. */
    public function canBeTried(): bool
    {
        return $this !== self::PasswordReset;
    }
}
