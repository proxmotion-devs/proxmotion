<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Servers\GuestScriptFailed;
use App\Enums\OsPlatform;
use App\Models\AppInstallation;
use App\Models\AppRun;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\GuestAgentResource;

/**
 * Stops app scripts inside the server (docs/adr/0030) by the name they were
 * started as (AppGuest::installName(), runName()): panel/recipes/_lib/stop.sh on
 * Linux, stop.ps1 on Windows. A name that is not running counts as stopped.
 * Nothing the script did is undone.
 */
final readonly class AppStopper
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private RecipeLoader $recipes,
    ) {}

    /**
     * Stop the runners started as $names for the installation.
     *
     * @param  non-empty-list<string>  $names
     *
     * @throws ProxmoxException when the agent does not answer
     * @throws GuestScriptFailed when one of them is still running
     */
    public function stop(AppInstallation $installation, array $names): void
    {
        $server = $installation->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        $this->stopWith($agent, $server->vmid, $installation, $names, 'The app\'s script could not be stopped: ');
    }

    /**
     * Before something new starts in the installation's directory: stop its
     * latest install attempt and its latest run other than $starting, which
     * a timeout or cancel may have left running (docs/adr/0030 §3).
     *
     * @throws ProxmoxException
     * @throws GuestScriptFailed when one of them is still running
     */
    public function stopEarlier(GuestAgentResource $agent, int $vmid, AppInstallation $installation, ?AppRun $starting = null): void
    {
        $names = [];

        if ($installation->attempts > 0) {
            $names[] = AppGuest::installName($installation->uuid, $installation->attempts);
        }

        $run = $installation->runs()
            ->when($starting !== null, fn ($query) => $query->whereKeyNot($starting?->id))
            ->latest('id')
            ->first();

        if ($run !== null) {
            $names[] = AppGuest::runName($run->uuid);
        }

        if ($names !== []) {
            $this->stopWith($agent, $vmid, $installation, $names, 'A previous script of this app is still running inside the server: ');
        }
    }

    /**
     * @param  non-empty-list<string>  $names
     *
     * @throws ProxmoxException
     */
    private function stopWith(GuestAgentResource $agent, int $vmid, AppInstallation $installation, array $names, string $failure): void
    {
        $platform = $installation->platform();
        $directory = $installation->guestDirectory();
        $script = $this->recipes->stopScript($platform);

        if ($platform === OsPlatform::Windows) {
            $file = AppGuest::STOP_FILES['windows'];
            AppGuest::makeDirectories($agent, $vmid, $platform, [$directory]);
            $agent->writeFile($vmid, $directory.'/'.$file, AppGuest::content($file, $script));
        }

        $result = $agent->run($vmid, AppGuest::stopCommand($platform, $directory, $script, $names));

        if (! $result->succeeded()) {
            $reason = trim(strtok($result->stderr."\n", "\n") ?: 'exit code '.$result->exitCode);

            throw new GuestScriptFailed($failure.$reason);
        }
    }
}
