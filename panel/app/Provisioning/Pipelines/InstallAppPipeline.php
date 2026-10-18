<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppStarter;
use App\Domain\Servers\GuestScriptFailed;
use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Models\Task;

/**
 * Starts the installer of an app installed later on a running server
 * (docs/adr/0015). The task ends once the installer runs detached;
 * apps:poll follows status.json until the app is installed.
 */
final readonly class InstallAppPipeline
{
    public function __construct(private AppStarter $starter) {}

    public function run(Task $task): void
    {
        $uuid = is_string($task->input['installation'] ?? null) ? $task->input['installation'] : '';
        $installation = AppInstallation::query()->with(['recipe', 'version'])->where('uuid', $uuid)->where('server_id', $task->server_id)->first();

        if ($installation === null) {
            throw new GuestScriptFailed('The app installation no longer exists.');
        }

        if ($installation->status !== AppInstallationStatus::Queued) {
            $task->completeStep('app_start', 'The installation was already started.');

            return;
        }

        $installation->setRelation('server', $task->server);
        $this->starter->start($task, $installation);
    }
}
