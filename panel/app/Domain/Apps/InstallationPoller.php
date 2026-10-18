<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Follows a running installer (docs/adr/0015) by reading its status.json
 * through the guest agent, and copies what the installer printed (docs/adr/0021).
 * When it reports "installed", the declared outputs are read and stored
 * encrypted; past the recipe's timeout the installation fails, also when the
 * agent stopped answering.
 */
final readonly class InstallationPoller
{
    /** The largest output file stored. */
    public const MAX_OUTPUT_FILE_BYTES = GuestOutputs::MAX_FILE_BYTES;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private AppInstallations $installations,
        private AppLogs $logs,
    ) {}

    /**
     * One poll at a time per installation: apps:poll and an administrator
     * watching the log (docs/adr/0021) may ask at the same moment.
     */
    public function poll(AppInstallation $installation): void
    {
        Cache::lock('app-installation-poll:'.$installation->id, 120)->get(fn () => $this->pollNow($installation->refresh()));
    }

    private function pollNow(AppInstallation $installation): void
    {
        if ($installation->status !== AppInstallationStatus::Installing) {
            return;
        }

        $server = $installation->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $timedOut = $installation->deadline()?->isPast() ?? false;
        $directory = $installation->guestDirectory();

        try {
            $status = GuestOutputs::status($agent, $server->vmid, $directory);
        } catch (ProxmoxException) {
            // Stopped, rebooting or the agent is down: try again next time, unless it is too late.
            $timedOut && $this->timedOut($installation);

            return;
        }

        if ($status['state'] === 'failed') {
            $this->logs->finish($this->logs->current($installation));
            $this->installations->failed($installation, $status['message'] ?? 'The installer failed.', $status['step']);

            return;
        }

        if ($status['state'] === 'installed') {
            try {
                $outputs = GuestOutputs::read($agent, $server->vmid, $directory, $installation->version->recipe()->outputs);
            } catch (ProxmoxException) {
                $timedOut && $this->timedOut($installation);

                return;
            }

            $this->logs->finish($this->logs->current($installation), $outputs);
            $this->installations->installed($installation, $outputs, $status['step']);

            return;
        }

        if ($timedOut) {
            $this->timedOut($installation);

            return;
        }

        $log = $this->logs->current($installation);
        $log !== null && $this->logs->follow($log);
        $installation->forceFill(['progress' => $status['progress'] ?? $installation->progress, 'step' => $status['step'] ?? $installation->step])->save();
    }

    private function timedOut(AppInstallation $installation): void
    {
        $this->logs->finish($this->logs->current($installation));
        $this->installations->failed($installation, __('The installation did not finish within :minutes minutes.', ['minutes' => (string) $installation->timeoutMinutes()]));
    }
}
