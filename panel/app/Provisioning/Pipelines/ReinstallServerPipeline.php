<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Enums\ServerStatus;
use App\Models\Task;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

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
    /** How long to wait for the guest agent of the new system to answer. */
    public const AGENT_TIMEOUT_SECONDS = 600;

    public function __construct(
        private ProxmoxClientFactory $clients,
        private CreateServerPipeline $create,
        private GuestScripts $guestScripts,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $server->forceFill(['status' => ServerStatus::Reinstalling])->save();

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
        $this->waitForGuestAgent($task);

        // Additional addresses and the template's script (ADR 0009, 0010); later also app installs (ADR 0004).
        $this->guestScripts->afterInstall($task, $server, waitForAgent: false);

        $server->forceFill(['status' => ServerStatus::Active, 'root_password_unrevealed' => true])->save();
    }

    /**
     * The new system is ready once its guest agent answers. If it never does
     * (template without agent, slow first boot), the server is still running:
     * the step records that and the reinstall succeeds.
     */
    private function waitForGuestAgent(Task $task): void
    {
        $server = $task->server;
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);
        $deadline = Carbon::now()->addSeconds(self::AGENT_TIMEOUT_SECONDS);
        $task->startStep('wait_agent');

        while (! $agent->ping($server->vmid)) {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                $task->completeStep('wait_agent', 'The guest agent did not answer within '.(self::AGENT_TIMEOUT_SECONDS / 60).' minutes.');

                return;
            }

            Sleep::for(5)->seconds();
        }

        $task->completeStep('wait_agent');
    }
}
