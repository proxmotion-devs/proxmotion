<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Jobs\PollAppInstallation;
use App\Jobs\PollAppRun;
use App\Models\AppInstallation;
use App\Models\AppRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:poll')]
#[Description('Queue a progress check for every app being installed and every running uninstall or action')]
class PollApps extends Command
{
    public function handle(): int
    {
        $installations = 0;
        $runs = 0;

        AppInstallation::query()
            ->where('status', AppInstallationStatus::Installing)
            ->eachById(function (AppInstallation $installation) use (&$installations) {
                PollAppInstallation::dispatch($installation);
                $installations++;
            });

        AppRun::query()
            ->where('status', AppRunStatus::Running)
            ->eachById(function (AppRun $run) use (&$runs) {
                PollAppRun::dispatch($run);
                $runs++;
            });

        $this->components->info("Queued {$installations} installation and {$runs} run checks.");

        return self::SUCCESS;
    }
}
