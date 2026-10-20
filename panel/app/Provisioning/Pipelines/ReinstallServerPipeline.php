<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppInstallations;
use App\Domain\Apps\AppStarter;
use App\Domain\Servers\GuestScripts;
use App\Enums\ServerStatus;
use App\Models\Task;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Stop → delete the VM and its disks → build it again from the chosen OS
 * template with the same VMID, IP addresses and MAC address → wait for the
 * guest agent (ADR 0004). The server's OS template, root password and SSH
 * keys were already updated when the reinstall was requested.
 *
 * Re-running after a failure is safe. If the old VM was already deleted by a
 * previous attempt, its MAC address is gone and the new VM gets a new one.
 */
final readonly class ReinstallServerPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private CreateServerPipeline $create,
        private GuestScripts $guestScripts,
        private AppInstallations $apps,
        private AppStarter $appStarter,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $server->forceFill(['status' => ServerStatus::Reinstalling])->save();
        $this->apps->serverWiped($server, 'The server was reinstalled.', keepOrdered: true);

        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);
        $previousNet0 = null;

        if (! $task->hasCompleted('destroy') && ($config = $qemu->config($server->vmid)) !== null) {
            $previousNet0 = is_string($config['net0'] ?? null) ? $config['net0'] : null;
            $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

            if ($qemu->status($server->vmid)['status'] === 'running') {
                // The disks are deleted next, so a clean shutdown gains nothing.
                $client->taskWaiter()->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
            }

            $task->completeStep('stop');
            $client->taskWaiter()->wait($qemu->destroy($server->vmid), timeoutSeconds: 600);
        }

        $task->completeStep('destroy');

        $this->create->build($task, $previousNet0);

        // The new system is ready once its guest agent answers (Windows: once setup has
        // finished); if it never does (template without agent), the server still runs
        // and the reinstall succeeds.
        $this->guestScripts->waitForAgentStep($task, $server, setup: true);

        // A server named from its location's template: its hostname inside the guest (ADR 0031).
        $this->create->nameGuest($task, $server);

        // Additional addresses and the template's script (ADR 0009, 0010).
        $this->guestScripts->afterInstall($task, $server);

        $server->forceFill(['status' => ServerStatus::Active, 'root_password_unrevealed' => true])->save();

        // The app chosen with the new system (ADR 0004): never fails the reinstall.
        $this->appStarter->startOrdered($task, $server);
    }
}
