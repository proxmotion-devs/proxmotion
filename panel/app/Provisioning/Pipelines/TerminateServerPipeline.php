<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppInstallations;
use App\Enums\ServerStatus;
use App\Jobs\DeleteBackupVolume;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\IpAllocator;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Stops and deletes the VM with its disks and backups, then frees its IP addresses.
 * The server row is kept (status "terminated") for history and billing.
 */
final readonly class TerminateServerPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private IpAllocator $ips,
        private AppInstallations $apps,
    ) {}

    /**
     * Backups of terminated servers are deleted at once (docs/adr/0051 §4),
     * each by a job of its own: deleting one can take minutes, and the
     * termination must not wait for all of them in one run. A backup that
     * cannot be deleted never stops the termination (DeleteBackupVolume).
     */
    private function deleteBackups(Task $task, Server $server): void
    {
        $backups = $server->backups()->pluck('id');

        if ($backups->isEmpty()) {
            return;
        }

        foreach ($backups as $id) {
            DeleteBackupVolume::dispatch($id);
        }

        $task->completeStep('delete_backups', 'Its backups are being deleted from their storage: '.$backups->count().'.');
    }

    public function run(Task $task): void
    {
        $server = $task->server;
        $this->apps->serverWiped($server, 'The server was terminated.');
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        if ($qemu->config($server->vmid) !== null) {
            // A shutdown, backup or other job may still hold the VM lock.
            $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

            if ($qemu->status($server->vmid)['status'] === 'running') {
                $client->taskWaiter()->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
            }

            $task->completeStep('stop');

            $client->taskWaiter()->wait($qemu->destroy($server->vmid), timeoutSeconds: 600);
        }

        // Its snapshots went with the VM (docs/adr/0051 §3).
        $server->snapshots()->delete();
        $task->completeStep('destroy');

        $this->deleteBackups($task, $server);

        $this->ips->release($server);
        $task->completeStep('release_ips');

        $server->forceFill([
            'status' => ServerStatus::Terminated,
            'terminated_at' => now(),
            'root_password' => null,
        ])->save();
    }
}
