<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settings\PanelSettings;
use App\Enums\ServerStatus;
use App\Jobs\DeleteBackupVolume;
use App\Models\Backup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:prune')]
#[Description('Delete the backups of servers terminated longer ago than Keep backups of terminated servers (docs/adr/0051 §6)')]
class PruneBackups extends Command
{
    public function handle(): int
    {
        $before = now()->subDays((int) PanelSettings::get('backups_keep_terminated_days'));
        $queued = 0;

        // Also those a deletion after the termination gave up on: tried again every day.
        Backup::query()
            ->whereHas('server', fn ($query) => $query
                ->where('status', ServerStatus::Terminated)
                ->where('terminated_at', '<=', $before))
            ->eachById(function (Backup $backup) use (&$queued) {
                DeleteBackupVolume::dispatch($backup->id);
                $queued++;
            });

        $this->components->info("Queued {$queued} backups of terminated servers for deletion.");

        return self::SUCCESS;
    }
}
