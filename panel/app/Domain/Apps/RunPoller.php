<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Jobs\StopAppScript;
use App\Models\AppInstallation;
use App\Models\AppRun;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;
use Illuminate\Support\Facades\Cache;

/**
 * Follows a running uninstall or action (docs/adr/0016 §4) through its
 * status.json in runs/<run>/. A finished action keeps its declared outputs
 * (encrypted); a finished uninstall removes the installation's directory,
 * which holds its inputs and outputs. Past its timeout the run fails, also
 * when the agent stopped answering, and the script is stopped (docs/adr/0030).
 */
final readonly class RunPoller
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private AppRuns $runs,
        private AppLogs $logs,
    ) {}

    /**
     * One poll at a time per run: apps:poll and an administrator watching
     * the log (docs/adr/0021) may ask at the same moment.
     */
    public function poll(AppRun $run): void
    {
        Cache::lock('app-run-poll:'.$run->id, 120)->get(fn () => $this->pollNow($run->refresh()));
    }

    private function pollNow(AppRun $run): void
    {
        if ($run->status !== AppRunStatus::Running) {
            return;
        }

        $server = $run->installation->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $timedOut = $run->deadline()?->isPast() ?? false;
        $directory = $run->guestDirectory();

        try {
            $status = GuestOutputs::status($agent, $server->vmid, $directory);
        } catch (ProxmoxException) {
            $timedOut && $this->timedOut($run);

            return;
        }

        if ($status['state'] === 'failed') {
            $this->logs->finish($run->log);
            $this->runs->failed($run, $status['message'] ?? 'The script failed.', $status['step']);

            return;
        }

        if ($status['state'] === 'succeeded') {
            try {
                $outputs = GuestOutputs::read($agent, $server->vmid, $directory, $run->declaredOutputs());
            } catch (ProxmoxException) {
                $timedOut && $this->timedOut($run);

                return;
            }

            $this->logs->finish($run->log, $outputs);
            $this->runs->succeeded($run, $outputs, $status['step']);

            if ($run->kind === AppRunKind::Uninstall) {
                $this->removeDirectory($agent, $server->vmid, $run->installation);
            }

            return;
        }

        if ($timedOut) {
            $this->timedOut($run);

            return;
        }

        $run->log !== null && $this->logs->follow($run->log);
        $run->forceFill(['progress' => $status['progress'] ?? $run->progress, 'step' => $status['step'] ?? $run->step])->save();
    }

    /**
     * Best effort: a directory left behind is harmless to the panel.
     */
    private function removeDirectory(GuestAgentResource $agent, int $vmid, AppInstallation $installation): void
    {
        try {
            $agent->run($vmid, AppGuest::removeCommand($installation->platform(), $installation->guestDirectory()));
        } catch (ProxmoxException $e) {
            report($e);
        }
    }

    private function timedOut(AppRun $run): void
    {
        $this->logs->finish($run->log);
        $this->runs->failed($run, __('The script did not finish within :minutes minutes.', ['minutes' => (string) $run->timeoutMinutes()]));
        StopAppScript::dispatch($run->installation->id, AppGuest::runName($run->uuid));
    }
}
