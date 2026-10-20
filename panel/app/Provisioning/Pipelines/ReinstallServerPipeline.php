<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppInstallations;
use App\Domain\Apps\AppStarter;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Stop → delete the VM and its disks → build it again from the chosen OS
 * template with the same VMID, IP addresses and MAC address → wait for the
 * guest agent (ADR 0004). The server's OS template, root password and SSH
 * keys were already updated when the reinstall was requested.
 *
 * Runs in stages like creating a server (docs/adr/0032), sharing its build
 * stages. Re-running after a failure is safe. If the old VM was already
 * deleted by a previous attempt, its MAC address is gone and the new VM gets
 * a new one.
 */
final readonly class ReinstallServerPipeline implements StagedPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private CreateServerPipeline $create,
        private AppInstallations $apps,
        private AppStarter $appStarter,
        private StageRunner $stages,
    ) {}

    public function stages(): array
    {
        return ['prepare', 'destroy', ...CreateServerPipeline::BUILD_STAGES, 'finish'];
    }

    public function runStage(Task $task, string $stage): ?int
    {
        $server = $task->server;

        return match ($stage) {
            'prepare' => $this->prepare($task, $server),
            'destroy' => $this->destroy($task, $server),
            'finish' => $this->finish($task, $server),
            default => $this->create->runBuildStage($task, $stage),
        };
    }

    /**
     * Stop the old VM and remember its net0, whose MAC address the new VM keeps.
     */
    private function prepare(Task $task, Server $server): null
    {
        $server->forceFill(['status' => ServerStatus::Reinstalling])->save();
        $this->apps->serverWiped($server, 'The server was reinstalled.', keepOrdered: true);

        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);
        $config = $qemu->config($server->vmid);

        if ($config === null) {
            return null;
        }

        $task->rememberInput('previous_net0', is_string($config['net0'] ?? null) ? $config['net0'] : null);
        $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

        if ($qemu->status($server->vmid)['status'] === 'running') {
            // The disks are deleted next, so a clean shutdown gains nothing.
            $client->taskWaiter()->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
        }

        $task->completeStep('stop');

        return null;
    }

    private function destroy(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->config($server->vmid) !== null ? $qemu->destroy($server->vmid) : null,
            timeoutSeconds: 600,
        );

        if ($delay === null) {
            $task->completeStep('destroy');
        }

        return $delay;
    }

    private function finish(Task $task, Server $server): null
    {
        $task->rememberInput('previous_net0', null);
        $server->forceFill(['status' => ServerStatus::Active, 'root_password_unrevealed' => true])->save();

        // The app chosen with the new system (ADR 0004): never fails the reinstall.
        $this->appStarter->startOrdered($task, $server);

        return null;
    }
}
