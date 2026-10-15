<?php

declare(strict_types=1);

namespace App\Proxmox;

use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxTaskFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Polls a Proxmox task (UPID) until it finishes.
 */
final readonly class TaskWaiter
{
    public function __construct(private ProxmoxClient $client) {}

    /**
     * @throws ProxmoxTaskFailed when the task fails or does not finish in time
     */
    public function wait(Upid $upid, int $timeoutSeconds = 600, int $pollSeconds = 2): void
    {
        $deadline = Carbon::now()->addSeconds($timeoutSeconds);

        while (true) {
            $status = $this->client->tasks()->status($upid);

            if (! $status['running']) {
                $exitStatus = $status['exitStatus'] ?? 'unknown';

                // "WARNINGS: n" means the task succeeded with warnings.
                if ($exitStatus !== 'OK' && ! str_starts_with($exitStatus, 'WARNINGS')) {
                    throw ProxmoxTaskFailed::withExitStatus($upid, $exitStatus);
                }

                return;
            }

            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                throw ProxmoxTaskFailed::timedOut($upid, $timeoutSeconds);
            }

            Sleep::for($pollSeconds)->seconds();
        }
    }
}
