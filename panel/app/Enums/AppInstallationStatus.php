<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * docs/adr/0015: queued → installing → installed; queued|installing → failed
 * (retryable); installed|failed → removed when the server is reinstalled or
 * terminated. docs/adr/0016: installed|failed|uninstall_failed →
 * uninstalling → removed | uninstall_failed. Actions run in AppRun records
 * and leave the status alone.
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

    /** Uninstalled, or the server was reinstalled or terminated. */
    case Removed = 'removed';

    /**
     * Still running: another app cannot be installed or uninstalled on the server meanwhile.
     */
    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Installing || $this === self::Uninstalling;
    }

    /**
     * Can be uninstalled (when its recipe has an uninstall script).
     */
    public function canUninstall(): bool
    {
        return in_array($this, [self::Installed, self::Failed, self::UninstallFailed], true);
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
            self::Removed => 'Removed',
        };
    }
}
