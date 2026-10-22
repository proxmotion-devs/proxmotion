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

        while (! $this->check($upid)) {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                throw ProxmoxTaskFailed::timedOut($upid, $timeoutSeconds);
            }

            Sleep::for($pollSeconds)->seconds();
        }
    }

    /**
     * One look at a task: whether it has finished. Staged tasks check their
     * Proxmox tasks this way between runs instead of waiting (docs/adr/0032).
     *
     * @throws ProxmoxTaskFailed when the task has failed
     */
    public function check(Upid $upid): bool
    {
        $status = $this->client->tasks()->status($upid);

        if ($status['running']) {
            return false;
        }

        $exitStatus = $status['exitStatus'] ?? 'unknown';

        // "WARNINGS: n" means the task succeeded with warnings.
        if ($exitStatus !== 'OK' && ! str_starts_with($exitStatus, 'WARNINGS')) {
            throw ProxmoxTaskFailed::withExitStatus($upid, $exitStatus);
        }

        return true;
    }

    /**
     * Wait until no task runs for the guest, so the next operation does not
     * fail with "can't lock file ... got timeout". Whether those tasks
     * succeeded does not matter.
     *
     * @throws ProxmoxTaskFailed when a task does not finish in time
     */
    public function waitForVm(string $node, int $vmid, int $timeoutSeconds = 300, int $pollSeconds = 2): void
    {
        foreach ($this->client->tasks()->activeForVm($node, $vmid) as $upid) {
            try {
                $this->wait($upid, $timeoutSeconds, $pollSeconds);
            } catch (ProxmoxTaskFailed $e) {
                if ($this->client->tasks()->status($upid)['running']) {
                    throw $e;
                }
            }
        }
    }
}
