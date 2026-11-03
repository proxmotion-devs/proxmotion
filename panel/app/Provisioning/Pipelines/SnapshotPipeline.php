<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Enums\AppInstallationStatus;
use App\Enums\GuestScriptEvent;
use App\Enums\PowerAction;
use App\Enums\TaskType;
use App\Models\AppInstallation;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Task;
use App\Provisioning\VmState;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use LogicException;
use RuntimeException;

/**
 * Takes, rolls back and deletes snapshots (docs/adr/0051 §3). A rollback
 * brings back the VM's configuration and disk of the snapshot's time; the
 * panel then writes its own state back (§5): resources, port speed, disk
 * size and firewall, and inside the guest its addresses, hostname and SSH
 * keys. Cloud-init settings stay as the snapshot has them. Every step looks
 * at Proxmox first, so running it again is safe.
 */
final readonly class SnapshotPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private VmState $vm,
        private PowerPipeline $power,
        private GuestScripts $guestScripts,
    ) {}

    public function run(Task $task): void
    {
        match ($task->type) {
            TaskType::CreateSnapshot => $this->take($task),
            TaskType::RollbackSnapshot => $this->rollback($task),
            TaskType::DeleteSnapshot => $this->delete($task),
            default => throw new LogicException("SnapshotPipeline cannot run a {$task->type->value} task."),
        };
    }

    private function take(Task $task): void
    {
        $server = $task->server;
        $name = (string) ($task->input['name'] ?? '');
        $label = is_string($task->input['label'] ?? null) ? $task->input['label'] : null;
        [$qemu, $client] = $this->qemu($server);

        if (! array_key_exists($name, $qemu->snapshots($server->vmid))) {
            $client->taskWaiter()->wait($qemu->snapshot($server->vmid, $name, $label ?? ''), timeoutSeconds: 1800);
        }

        $snapshot = Snapshot::query()->where('server_id', $server->id)->where('name', $name)->first() ?? new Snapshot;
        $snapshot->forceFill(['server_id' => $server->id, 'name' => $name, 'label' => $label, 'task_id' => $task->id])->save();
        $task->completeStep('snapshot');
    }

    private function rollback(Task $task): void
    {
        $server = $task->server;
        $snapshot = $this->snapshot($task);
        [$qemu, $client] = $this->qemu($server);

        // Whether to start it again afterwards: kept from the first run, since the rollback stops it.
        $wasRunning = $task->input['was_running'] ?? null;

        if (! is_bool($wasRunning)) {
            $wasRunning = $qemu->status($server->vmid)['status'] === 'running';
            $task->forceFill(['input' => [...($task->input ?? []), 'was_running' => $wasRunning]])->save();
        }

        if (! $task->hasCompleted('rollback')) {
            $client->taskWaiter()->wait($qemu->rollback($server->vmid, $snapshot->name), timeoutSeconds: 1800);

            // Apps installed since the snapshot are gone from the disk.
            AppInstallation::query()
                ->where('server_id', $server->id)
                ->where('created_at', '>', $snapshot->created_at)
                ->where('status', '!=', AppInstallationStatus::Removed)
                ->update(['status' => AppInstallationStatus::Removed, 'updated_at' => now()]);
            $task->completeStep('rollback');
        }

        // The panel's state back over the snapshot's (docs/adr/0051 §5).
        $this->vm->configure($server, $qemu);
        $task->completeStep('configure');
        $grown = $this->vm->growDisk($server, $qemu, $client->taskWaiter());
        $task->completeStep('resize');
        $this->vm->firewall($server, $qemu);
        $task->completeStep('firewall');

        // The guest's root password is the one of the snapshot's time; the panel's is no longer right.
        // Its addresses, hostname and SSH keys are too: they go in at the (next) start (GuestRefresh).
        $server->forceFill(['root_password' => null, 'root_password_unrevealed' => false, 'reboot_required' => false, 'guest_refresh_pending' => true])->save();

        if (! $wasRunning) {
            $task->completeStep('start', 'The server was not running before the rollback and stays stopped. Its addresses, hostname and SSH keys are written into it when it is started through the panel.');

            return;
        }

        // Starting it runs the guest refresh (PowerPipeline).
        $this->power->power($task, PowerAction::Start);

        if ($grown) {
            $this->guestScripts->run($task, $server, GuestScriptEvent::DiskResized);
        }
    }

    private function delete(Task $task): void
    {
        $server = $task->server;
        $snapshot = $this->snapshot($task);
        [$qemu, $client] = $this->qemu($server);

        // Gone already (deleted in Proxmox, or by an earlier run): nothing left to do there.
        if (array_key_exists($snapshot->name, $qemu->snapshots($server->vmid))) {
            $client->taskWaiter()->wait($qemu->deleteSnapshot($server->vmid, $snapshot->name), timeoutSeconds: 1800);
        }

        $snapshot->delete();
        $task->completeStep('delete_snapshot');
    }

    private function snapshot(Task $task): Snapshot
    {
        return Snapshot::query()
            ->where('server_id', $task->server_id)
            ->where('uuid', (string) ($task->input['snapshot'] ?? ''))
            ->first() ?? throw new RuntimeException('The snapshot no longer exists.');
    }

    /**
     * @return array{0: QemuResource, 1: ProxmoxClient}
     */
    private function qemu(Server $server): array
    {
        $client = $this->clients->forCluster($server->cluster);
        $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

        return [$client->qemu($server->node->name), $client];
    }
}
