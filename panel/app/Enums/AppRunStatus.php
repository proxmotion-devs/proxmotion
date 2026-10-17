<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * queued → running → succeeded | failed (docs/adr/0016 §4).
 */
enum AppRunStatus: string
{
    /** Waiting for the app_run task to start the script. */
    case Queued = 'queued';

    /** The script runs inside the server; apps:poll follows its status.json. */
    case Running = 'running';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
