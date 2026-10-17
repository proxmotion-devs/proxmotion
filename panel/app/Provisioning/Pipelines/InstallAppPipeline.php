<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppInstallations;
use App\Domain\Apps\GuestPackage;
use App\Domain\Servers\GuestScriptFailed;
use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Models\Server;
use App\Models\Task;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Throwable;

/**
 * Starts an app installer inside the server (docs/adr/0015): writes the
 * installation's recipe version, the shared helpers and runner, inputs.json
 * and server.json to /var/lib/proxmotion/apps/<installation>/ through the
 * guest agent, then starts the runner detached with systemd-run. The task
 * ends there; apps:poll follows status.json until the app is installed.
 */
final readonly class InstallAppPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestPackage $package,
        private AppInstallations $installations,
    ) {}

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

        try {
            $this->start($task, $task->server, $installation);
        } catch (Throwable $e) {
            $this->installations->failed($installation, $e instanceof ProxmoxException ? $e->getMessage() : 'The installer could not be started.');

            throw $e;
        }
    }

    private function start(Task $task, Server $server, AppInstallation $installation): void
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $directory = $installation->guestDirectory();

        $task->startStep('app_upload');

        if (! $agent->ping($server->vmid)) {
            throw new GuestScriptFailed('The guest agent did not answer: is the server running with qemu-guest-agent?');
        }

        $this->package->upload($agent, $server, $installation);
        $agent->writeFile($server->vmid, $directory.'/status.json', GuestPackage::startingStatus());

        $task->completeStep('app_upload');
        $task->startStep('app_start');

        $attempt = $installation->attempts + 1;
        $result = $agent->run($server->vmid, [
            'systemd-run', '--unit=proxmotion-app-'.$installation->uuid.'-'.$attempt, '--collect', '--quiet',
            '/bin/bash', $directory.'/run.sh',
        ]);

        if (! $result->succeeded()) {
            $reason = trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode);

            throw new GuestScriptFailed('The installer could not be started: '.$reason);
        }

        $installation->forceFill([
            'status' => AppInstallationStatus::Installing,
            'attempts' => $attempt,
            'progress' => 0,
            'step' => 'Starting',
            'error' => null,
            'task_id' => $task->id,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        $task->completeStep('app_start');
    }
}
