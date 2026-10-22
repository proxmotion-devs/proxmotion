<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Apps\AppStopper;
use App\Enums\ServerStatus;
use App\Models\AppInstallation;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stops an app script inside the server after a timeout or a cancel
 * (docs/adr/0030). The name is fixed when the job is queued, so a retry that
 * started meanwhile keeps running. A stop that never reaches the server is
 * covered by the next start, which stops earlier scripts first.
 */
class StopAppScript implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    private const int RETRY_SECONDS = 60;

    public function __construct(public int $installationId, public string $name) {}

    public function handle(AppStopper $stopper): void
    {
        $installation = AppInstallation::query()->with(['server.cluster', 'server.node', 'version'])->find($this->installationId);

        if ($installation === null || in_array($installation->server->status, [ServerStatus::Terminating, ServerStatus::Terminated], true)) {
            return;
        }

        try {
            $stopper->stop($installation, [$this->name]);
        } catch (ProxmoxException) {
            // The agent did not answer for a moment, or the script outlived the stop: try again later.
            if ($this->attempts() < $this->tries) {
                $this->release(self::RETRY_SECONDS);
            }
        }
    }
}
