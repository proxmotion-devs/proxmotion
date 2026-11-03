<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a backup came about (docs/adr/0051 §4, §6). Manual backups are never
 * deleted automatically; scheduled ones are pruned beyond the server's
 * backup slots (phase 3).
 */
enum BackupKind: string
{
    /** Taken by an administrator or through the billing API. */
    case Manual = 'manual';

    /** Taken by the panel's backup schedule (phase 3). */
    case Scheduled = 'scheduled';
}
