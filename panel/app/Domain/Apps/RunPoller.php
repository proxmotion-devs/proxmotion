<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\AppRun;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;

/**
 * Follows a running uninstall or action (docs/adr/0016 §4) through its
 * status.json in runs/<run>/. A finished action keeps its declared outputs
 * (encrypted); a finished uninstall removes the installation's directory,
 * which holds its inputs and outputs. Past its timeout the run fails, also
 * when the agent stopped answering.
 */
final readonly class RunPoller
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private AppRuns $runs,
    ) {}

    public function poll(AppRun $run): void
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

            $this->runs->succeeded($run, $outputs, $status['step']);

            if ($run->kind === AppRunKind::Uninstall) {
                $this->removeDirectory($agent, $server->vmid, $run->installation->guestDirectory());
            }

            return;
        }

        if ($timedOut) {
            $this->timedOut($run);

            return;
        }

        $run->forceFill(['progress' => $status['progress'] ?? $run->progress, 'step' => $status['step'] ?? $run->step])->save();
    }

    /**
     * Best effort: a directory left behind is harmless to the panel.
     */
    private function removeDirectory(GuestAgentResource $agent, int $vmid, string $directory): void
    {
        try {
            $agent->run($vmid, ['rm', '-rf', '--one-file-system', $directory]);
        } catch (ProxmoxException $e) {
            report($e);
        }
    }

    private function timedOut(AppRun $run): void
    {
        $this->runs->failed($run, __('The script did not finish within :minutes minutes.', ['minutes' => (string) $run->timeoutMinutes()]));
    }
}
