<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppGuest;
use App\Domain\Apps\AppLogs;
use App\Domain\Apps\AppRuns;
use App\Domain\Apps\AppStopper;
use App\Domain\Apps\GuestPackage;
use App\Domain\Servers\GuestScriptFailed;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\AppRun;
use App\Models\Task;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use stdClass;
use Throwable;

/**
 * Starts an uninstall, upgrade or action inside the server (docs/adr/0016 §4):
 * writes the installation's recipe version again (an upgrade: the version it
 * upgrades to, with its install inputs), the run's inputs.json and
 * a fresh status.json to runs/<run>/ of the installation's directory, then
 * starts `run.sh <script> <run directory>` detached with systemd-run (on
 * Windows run.ps1 as a scheduled task, docs/adr/0029). The
 * task ends there; apps:poll follows the run's status.json.
 */
final readonly class AppRunPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestPackage $package,
        private AppRuns $runs,
        private AppLogs $logs,
        private AppStopper $stopper,
    ) {}

    public function run(Task $task): void
    {
        $uuid = is_string($task->input['run'] ?? null) ? $task->input['run'] : '';
        $run = AppRun::query()
            ->with(['installation.recipe', 'installation.version', 'targetVersion'])
            ->where('uuid', $uuid)
            ->whereHas('installation', fn ($query) => $query->where('server_id', $task->server_id))
            ->first();

        if ($run === null) {
            throw new GuestScriptFailed('The app run no longer exists.');
        }

        if ($run->status !== AppRunStatus::Queued) {
            $task->completeStep('app_start', 'The run was already started.');

            return;
        }

        try {
            $this->start($task, $run);
        } catch (Throwable $e) {
            $this->runs->failed($run, $e instanceof ProxmoxException ? $e->getMessage() : 'The script could not be started.');

            throw $e;
        }
    }

    private function start(Task $task, AppRun $run): void
    {
        $server = $task->server;
        $installation = $run->installation;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $directory = $run->guestDirectory();
        $script = $run->script();

        $task->startStep('app_upload');

        if (! $agent->ping($server->vmid)) {
            throw new GuestScriptFailed('The guest agent did not answer: is the server running with qemu-guest-agent?');
        }

        // An install attempt or run past its timeout must not run next to this one (docs/adr/0030 §3).
        $this->stopper->stopEarlier($agent, $server->vmid, $installation, $run);

        // An upgrade writes the version it upgrades to, with the install inputs for it (docs/adr/0016 §4).
        $upgrade = $run->kind === AppRunKind::Upgrade;
        $this->package->upload($agent, $server, $installation, $upgrade ? $run->runVersion() : null, $upgrade ? $run->inputs : null);
        AppGuest::makeDirectories($agent, $server->vmid, $installation->platform(), [$directory]);
        $agent->writeFile($server->vmid, $directory.'/inputs.json', GuestPackage::json($run->inputs === [] ? new stdClass : $run->inputs));
        $agent->writeFile($server->vmid, $directory.'/status.json', GuestPackage::startingStatus());

        $task->completeStep('app_upload');
        $task->startStep('app_start');

        $result = $agent->run($server->vmid, AppGuest::startCommand($installation->platform(), $installation->guestDirectory(), AppGuest::runName($run->uuid), $script, $directory));

        if (! $result->succeeded()) {
            $reason = trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode);

            throw new GuestScriptFailed('The script could not be started: '.$reason);
        }

        $run->forceFill([
            'status' => AppRunStatus::Running,
            'progress' => 0,
            'step' => 'Starting',
            'task_id' => $task->id,
            'started_at' => now(),
        ])->save();
        $this->logs->beginRun($run);

        $task->completeStep('app_start');
    }
}
