<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Apps\RunPoller;
use App\Models\AppRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads the progress of one running uninstall or action (docs/adr/0016 §4),
 * queued every minute by apps:poll.
 */
class PollAppRun implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public AppRun $run) {}

    public function uniqueId(): string
    {
        return $this->run->uuid;
    }

    public function handle(RunPoller $poller): void
    {
        $poller->poll($this->run->refresh());
    }
}
