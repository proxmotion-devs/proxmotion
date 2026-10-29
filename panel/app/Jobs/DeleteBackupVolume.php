<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Backup;
use App\Provisioning\Pipelines\DeleteBackupPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes one backup of a terminated server from its storage
 * (docs/adr/0051 §4), queued by TerminateServerPipeline so the termination
 * does not wait for every backup. Tried again later when the storage does
 * not answer; a backup that still cannot be deleted keeps its row (under
 * the terminated server) and is logged, so it can be found and removed by
 * hand.
 */
class DeleteBackupVolume implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Longer than DeleteBackupPipeline's wait for the delete task. */
    public int $timeout = 900;

    private const int RETRY_SECONDS = 300;

    public function __construct(public int $backupId) {}

    public function handle(DeleteBackupPipeline $pipeline): void
    {
        $backup = Backup::query()->with(['cluster', 'node'])->find($this->backupId);

        if ($backup === null) {
            return;
        }

        try {
            $pipeline->remove($backup);
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_SECONDS);

                return;
            }

            Log::warning('A backup of a terminated server could not be deleted from its storage.', [
                'backup' => $backup->uuid,
                'storage' => $backup->storage,
                'volume' => $backup->volume,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
