<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backups\BackupSchedules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:schedule')]
#[Description('Queue the scheduled backups that are due in the backup window (docs/adr/0051 §6)')]
class ScheduleBackups extends Command
{
    public function handle(BackupSchedules $schedules): int
    {
        $queued = $schedules->queueDue(now());

        $this->components->info("Queued {$queued} scheduled backups.");

        return self::SUCCESS;
    }
}
