<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppInstallationStatus;
use App\Jobs\PollAppInstallation;
use App\Models\AppInstallation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:poll')]
#[Description('Queue a progress check for every app that is being installed')]
class PollApps extends Command
{
    public function handle(): int
    {
        $count = 0;

        AppInstallation::query()
            ->where('status', AppInstallationStatus::Installing)
            ->eachById(function (AppInstallation $installation) use (&$count) {
                PollAppInstallation::dispatch($installation);
                $count++;
            });

        $this->components->info("Queued {$count} installation checks.");

        return self::SUCCESS;
    }
}
