<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Servers\GuestScriptFailed;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\OsPlatform;
use App\Models\AppInstallation;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Network\ServerFirewall;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts an app installer inside the server (docs/adr/0015): writes the
 * installation's recipe version, the shared helpers and runner, inputs.json
 * and server.json to /var/lib/proxmotion/apps/<installation>/ through the
 * guest agent, then starts the runner detached with systemd-run (on Windows
 * a scheduled task, C:/ProgramData/Proxmotion/apps; docs/adr/0029). apps:poll
 * follows status.json until the app is installed.
 *
 * The install_app task starts apps installed later; the create and
 * reinstall pipelines start the app chosen with the system once its guest
 * agent answers (docs/adr/0004, 0016 §5).
 */
final readonly class AppStarter
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private GuestPackage $package,
        private AppInstallations $installations,
        private AppLogs $logs,
        private AppStopper $stopper,
        private ServerFirewall $firewall,
    ) {}

    /**
     * Start a queued installation. If that fails, the installation fails too
     * (it can be retried) and the error is thrown on.
     *
     * @throws Throwable
     */
    public function start(Task $task, AppInstallation $installation): void
    {
        try {
            $this->upload($task, $installation->server, $installation);
        } catch (Throwable $e) {
            $this->installations->failed($installation, $e instanceof ProxmoxException ? $e->getMessage() : 'The installer could not be started.');

            throw $e;
        }
    }

    /**
     * The post-boot hook of a new or reinstalled system: start the app it was
     * ordered with, after the pipeline waited for its agent. A failure fails
     * the installation, never the server.
     */
    public function startOrdered(Task $task, Server $server): void
    {
        $installation = $server->appInstallations()
            ->with(['recipe', 'version'])
            ->where('mode', AppInstallMode::Order)
            ->where('status', AppInstallationStatus::Queued)
            ->latest('id')
            ->first();

        if ($installation === null) {
            return;
        }

        $installation->setRelation('server', $server);

        try {
            $this->start($task, $installation);
        } catch (Throwable $e) {
            if (! $e instanceof ProxmoxException) {
                report($e);
            }

            // The installation holds the error; the step only says the server is fine without the app.
            $task->completeStep($task->hasCompleted('app_upload') ? 'app_start' : 'app_upload', 'The app was not installed; it can be retried.');
        }
    }

    private function upload(Task $task, Server $server, AppInstallation $installation): void
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $directory = $installation->guestDirectory();

        $task->startStep('app_upload');

        if (! $agent->ping($server->vmid)) {
            throw new GuestScriptFailed('The guest agent did not answer: is the server running with qemu-guest-agent?');
        }

        // An earlier attempt or run past its timeout must not run next to this one (docs/adr/0030 §3).
        $this->stopper->stopEarlier($agent, $server->vmid, $installation);
        $this->package->upload($agent, $server, $installation);
        $agent->writeFile($server->vmid, $directory.'/status.json', GuestPackage::startingStatus());

        $task->completeStep('app_upload');
        $task->startStep('app_start');

        $attempt = $installation->attempts + 1;
        $platform = $installation->platform();
        $script = $installation->version->recipe()->lifecycleScript('install') ?? ($platform === OsPlatform::Windows ? 'install.ps1' : 'install.sh');
        $result = $agent->run($server->vmid, AppGuest::startCommand($platform, $directory, AppGuest::installName($installation->uuid, $attempt), $script));

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
        $this->logs->beginAttempt($installation);

        // Its ports, while the server's firewall drops by default (docs/adr/0035 §4).
        if ($server->blocksByDefault()) {
            try {
                $this->firewall->apply($this->clients->forCluster($server->cluster)->qemu($server->node->name), $server);
            } catch (ProxmoxException $e) {
                // The firewall check reports it; the installer runs anyway.
                Log::warning('Could not open the ports of an app in the server firewall.', ['server' => $server->uuid, 'error' => $e->getMessage()]);
            }
        }

        $task->completeStep('app_start');
    }
}
