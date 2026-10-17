<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * docs/adr/0015: queued → installing → installed; queued|installing → failed
 * (retryable); installed|failed → removed when the server is reinstalled or
 * terminated.
 */
enum AppInstallationStatus: string
{
    /** Waiting for the install_app task to start the installer. */
    case Queued = 'queued';

    /** The installer runs inside the server; apps:poll follows status.json. */
    case Installing = 'installing';

    case Installed = 'installed';

    case Failed = 'failed';

    /** The server was reinstalled or terminated. */
    case Removed = 'removed';

    /**
     * Still running: another app cannot be installed on the server meanwhile.
     */
    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Installing;
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
            self::Removed => 'Removed',
        };
    }
}
