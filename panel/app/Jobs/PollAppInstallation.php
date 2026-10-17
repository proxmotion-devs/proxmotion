<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Apps\InstallationPoller;
use App\Models\AppInstallation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the progress of one running app installer (docs/adr/0015), queued
 * every minute by apps:poll.
 */
class PollAppInstallation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public AppInstallation $installation) {}

    public function uniqueId(): string
    {
        return $this->installation->uuid;
    }

    public function handle(InstallationPoller $poller): void
    {
        $poller->poll($this->installation->refresh());
    }
}
