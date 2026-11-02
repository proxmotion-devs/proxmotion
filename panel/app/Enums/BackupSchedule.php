<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How often the panel backs a server up by itself (docs/adr/0051 §6), in
 * the backup window of Panel settings.
 */
enum BackupSchedule: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * Admin label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'No scheduled backups',
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
        };
    }
}
