<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * docs/adr/0015: queued → installing → installed; queued|installing → failed
 * (retryable); installed|failed → removed when the server is reinstalled or
 * terminated. docs/adr/0016: installed|failed|uninstall_failed|upgrade_failed
 * → uninstalling → removed | uninstall_failed; installed|upgrade_failed →
 * upgrading → installed (new version) | upgrade_failed (old version kept).
 * Actions run in AppRun records and leave the status alone.
 */
enum AppInstallationStatus: string
{
    /** Waiting for the install_app task to start the installer. */
    case Queued = 'queued';

    /** The installer runs inside the server; apps:poll follows status.json. */
    case Installing = 'installing';

    case Installed = 'installed';

    case Failed = 'failed';

    /** The uninstall script runs inside the server. */
    case Uninstalling = 'uninstalling';

    /** The uninstall script failed; the app may be partly removed. Uninstalling can be tried again. */
    case UninstallFailed = 'uninstall_failed';

    /** The upgrade script of a newer recipe version runs inside the server. */
    case Upgrading = 'upgrading';

    /** The upgrade script failed; the installation keeps its old version. Upgrading can be tried again. */
    case UpgradeFailed = 'upgrade_failed';

    /** Uninstalled, or the server was reinstalled or terminated. */
    case Removed = 'removed';

    /**
     * Statuses of a script still running: another app cannot be installed,
     * uninstalled or upgraded on the server meanwhile.
     *
     * @return list<self>
     */
    public static function busy(): array
    {
        return [self::Queued, self::Installing, self::Uninstalling, self::Upgrading];
    }

    public function isBusy(): bool
    {
        return in_array($this, self::busy(), true);
    }

    /**
     * Statuses of an app that is on the server: installing it again would
     * run its installer over it.
     *
     * @return list<self>
     */
    public static function onServer(): array
    {
        return [self::Installed, self::Uninstalling, self::UninstallFailed, self::Upgrading, self::UpgradeFailed];
    }

    /**
     * Can be uninstalled (when its recipe has an uninstall script).
     */
    public function canUninstall(): bool
    {
        return in_array($this, [self::Installed, self::Failed, self::UninstallFailed, self::UpgradeFailed], true);
    }

    /**
     * Can be upgraded (when a newer version of its recipe accepts it).
     */
    public function canUpgrade(): bool
    {
        return $this === self::Installed || $this === self::UpgradeFailed;
    }

    /**
     * Admin label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Installing => 'Installing',
            self::Installed => 'Installed',
            self::Failed => 'Failed',
            self::Uninstalling => 'Uninstalling',
            self::UninstallFailed => 'Uninstall failed',
            self::Upgrading => 'Upgrading',
            self::UpgradeFailed => 'Upgrade failed',
            self::Removed => 'Removed',
        };
    }
}
