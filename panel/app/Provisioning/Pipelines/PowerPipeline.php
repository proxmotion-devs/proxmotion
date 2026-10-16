<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Models\Task;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

/**
 * Suspending stops the VM (clean shutdown, forced after a timeout) and keeps
 * it stopped; unsuspending starts it again. Disks and IPs are kept.
 *
 * Power actions (start, shutdown, stop, reboot) change only the VM's power
 * state, never the server's status. They do nothing when the VM is already in
 * the requested state, so re-running them is safe.
 */
final readonly class PowerPipeline
{
    /** How long a rebooted VM may take to start again after its shutdown. */
    private const REBOOT_START_SECONDS = 60;

    public function __construct(private ProxmoxClientFactory $clients) {}

    public function power(Task $task, PowerAction $action): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);
        $waiter = $client->taskWaiter();
        $waiter->waitForVm($server->node->name, $server->vmid);

        $running = $qemu->status($server->vmid)['status'] === 'running';

        if ($action === PowerAction::Start && ! $running) {
            $waiter->wait($qemu->power($server->vmid, 'start'), timeoutSeconds: 300);
        } elseif ($action === PowerAction::Shutdown && $running) {
            // Never forced: if the guest ignores it, the task fails and Stop is the way out.
            $waiter->wait($qemu->power($server->vmid, 'shutdown', forceStop: false), timeoutSeconds: 180);
        } elseif ($action === PowerAction::Stop && $running) {
            $waiter->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
        } elseif ($action === PowerAction::Reboot) {
            // Rebooting a stopped VM starts it.
            $waiter->wait($qemu->power($server->vmid, $running ? 'reboot' : 'start'), timeoutSeconds: 300);

            if ($running && ! $this->waitUntilRunning($qemu, $server->vmid)) {
                $task->completeStep($action->value, 'The server was shut down but had not started again after '.self::REBOOT_START_SECONDS.' seconds.');

                return;
            }
        }

        $task->completeStep($action->value);
    }

    /**
     * The reboot task ends once the guest has shut down; Proxmox starts it
     * again a few seconds later (qmeventd). Seen on PVE 9.2.2.
     */
    private function waitUntilRunning(QemuResource $qemu, int $vmid): bool
    {
        $deadline = Carbon::now()->addSeconds(self::REBOOT_START_SECONDS);

        while ($qemu->status($vmid)['status'] !== 'running') {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            Sleep::for(2)->seconds();
        }

        return true;
    }

    public function suspend(Task $task): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);
        $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

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
        $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

        if ($qemu->status($server->vmid)['status'] !== 'running') {
            $client->taskWaiter()->wait($qemu->power($server->vmid, 'start'), timeoutSeconds: 300);
        }

        $task->completeStep('start');
        $server->forceFill(['status' => ServerStatus::Active, 'suspension_reason' => null])->save();
    }
}
