<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Enums\ServerStatus;
use App\Models\Task;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Suspending stops the VM (clean shutdown, forced after a timeout) and keeps
 * it stopped; unsuspending starts it again. Disks and IPs are kept.
 */
final readonly class PowerPipeline
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    public function suspend(Task $task): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        if ($qemu->status($server->vmid)['status'] === 'running') {
            $client->taskWaiter()->wait($qemu->power($server->vmid, 'shutdown'), timeoutSeconds: 180);
        }

        $task->completeStep('stop');
        $server->forceFill(['status' => ServerStatus::Suspended])->save();
    }

    public function unsuspend(Task $task): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        if ($qemu->status($server->vmid)['status'] !== 'running') {
            $client->taskWaiter()->wait($qemu->power($server->vmid, 'start'), timeoutSeconds: 300);
        }

        $task->completeStep('start');
        $server->forceFill(['status' => ServerStatus::Active, 'suspension_reason' => null])->save();
    }
}
