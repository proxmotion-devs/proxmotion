<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Domain\Settings\PanelSettings;
use App\Enums\AppInstallationStatus;
use App\Enums\GuestScriptEvent;
use App\Models\AppInstallation;
use App\Models\Backup;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\GuestRefresh;
use App\Provisioning\Stages\HasOwnDeadline;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Provisioning\VmLabels;
use App\Provisioning\VmState;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\Data\Upid;
use App\Proxmox\ProxmoxClientFactory;
use RuntimeException;

/**
 * Replaces a server's VM with a backup of it (docs/adr/0051 §4): stop →
 * restore over the VM (same VMID, the backup's MAC address, the disks on
 * the server's storage) → the panel's state written back (§5): resources,
 * port speed, disk size, firewall, notes and tags → started again when it
 * ran → its addresses, hostname and SSH keys written into the guest.
 * Cloud-init settings stay as the backup has them. The restore deletes the
 * VM's snapshots. Staged (docs/adr/0032) with the backup deadline.
 */
final readonly class RestoreBackupPipeline implements HasOwnDeadline, StagedPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private StageRunner $stages,
        private VmState $vm,
        private GuestScripts $guestScripts,
        private GuestRefresh $refresh,
    ) {}

    public function stages(): array
    {
        return ['stop', 'restore', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'refresh'];
    }

    public function deadlineMinutes(): int
    {
        return (int) PanelSettings::get('backup_deadline_minutes');
    }

    public function runStage(Task $task, string $stage): ?int
    {
        $server = $task->server;

        return match ($stage) {
            'stop' => $this->stop($task, $server),
            'restore' => $this->restore($task, $server, $this->backup($task)),
            'configure' => $this->configure($task, $server),
            'resize' => $this->resize($task, $server),
            'firewall' => $this->firewall($task, $server),
            'start' => $this->start($task, $server),
            'wait_agent' => $this->wasRunning($task) ? $this->waitForAgent($task, $server) : null,
            'refresh' => $this->wasRunning($task) ? $this->refreshGuest($task, $server) : null,
            default => throw new RuntimeException("Unknown stage {$stage}."),
        };
    }

    /**
     * Remember whether it ran, then stop it: its disks are replaced next,
     * so a clean shutdown gains nothing. A VM an earlier failed restore left
     * without a configuration is restored from scratch (vm_missing). A task
     * that still holds the VM (a shutdown, a backup) is waited for between
     * runs.
     */
    private function stop(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        if (! is_bool($task->input['was_running'] ?? null)) {
            if ($qemu->config($server->vmid) === null) {
                $task->rememberInput('vm_missing', true);
                $task->rememberInput('was_running', false);
                $task->completeStep('stop', 'The VM has no configuration (an earlier restore failed): it is restored from scratch.');

                return null;
            }

            if ($client->tasks()->activeForVm($server->node->name, $server->vmid) !== []) {
                return 5;
            }

            $task->rememberInput('was_running', $qemu->status($server->vmid)['status'] === 'running');
        }

        if (($task->input['vm_missing'] ?? false) === true) {
            return null;
        }

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->status($server->vmid)['status'] === 'running' ? $qemu->power($server->vmid, 'stop') : null,
            timeoutSeconds: 120,
        );

        if ($delay === null) {
            $task->completeStep('stop');
        }

        return $delay;
    }

    private function restore(Task $task, Server $server, Backup $backup): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            // A VM restored from scratch joins the resource pool again (its pool privileges no longer reach it otherwise).
            fn () => $qemu->restore($server->vmid, $backup->volume, $server->storage?->name, ($task->input['vm_missing'] ?? false) === true ? $server->cluster->resource_pool : null),
            timeoutSeconds: $this->deadlineMinutes() * 60,
            pollSeconds: 15,
        );

        if ($delay !== null) {
            return $delay;
        }

        // The restore replaced the VM's disks, and its snapshots with them.
        $server->snapshots()->delete();
        // Apps installed since the backup are gone from the disk.
        AppInstallation::query()
            ->where('server_id', $server->id)
            ->where('created_at', '>', $backup->created_at)
            ->where('status', '!=', AppInstallationStatus::Removed)
            ->update(['status' => AppInstallationStatus::Removed, 'updated_at' => now()]);
        // The guest's system and root password are those of the backup's time; its
        // addresses, hostname and SSH keys go in at the (next) start (GuestRefresh).
        $server->forceFill([
            'os_template_id' => $backup->os_template_id ?? $server->os_template_id,
            'root_password' => null,
            'root_password_unrevealed' => false,
            'reboot_required' => false,
            'guest_refresh_pending' => true,
        ])->save();
        $task->completeStep('restore');

        return null;
    }

    /**
     * The panel's state back over the backup's (docs/adr/0051 §5).
     */
    private function configure(Task $task, Server $server): null
    {
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);

        $this->vm->configure($server, $qemu);
        $task->completeStep('configure', VmLabels::apply($qemu, $server));

        return null;
    }

    /**
     * Grow the restored disk to the server's size, checked between runs.
     * "grown" is only ever set, so a stage run again still runs the
     * disk_resized script afterwards.
     */
    private function resize(Task $task, Server $server): ?int
    {
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask($task, $client, function () use ($task, $server, $qemu): ?Upid {
            $disk = BootDisk::fromConfig($qemu->config($server->vmid) ?? []) ?? throw new RuntimeException('Could not find the boot disk of the VM.');

            if ($disk->sizeMib >= $server->disk_mib) {
                return null;
            }

            $task->rememberInput('grown', true);

            return $qemu->resize($server->vmid, $disk->key, $server->disk_mib);
        }, timeoutSeconds: 600);

        if ($delay === null) {
            $task->completeStep('resize');
        }

        return $delay;
    }

    private function firewall(Task $task, Server $server): null
    {
        $this->vm->firewall($server, $this->clients->forCluster($server->cluster)->qemu($server->node->name));
        $task->completeStep('firewall');

        return null;
    }

    private function start(Task $task, Server $server): ?int
    {
        if (! $this->wasRunning($task)) {
            $task->completeStep('start', 'The server was not running before the restore and stays stopped. Its addresses, hostname and SSH keys are written into it when it is started through the panel.');

            return null;
        }

        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $delay = $this->stages->proxmoxTask(
            $task,
            $client,
            fn () => $qemu->status($server->vmid)['status'] !== 'running' ? $qemu->power($server->vmid, 'start') : null,
            timeoutSeconds: 300,
        );

        if ($delay === null) {
            $task->completeStep('start');
        }

        return $delay;
    }

    private function waitForAgent(Task $task, Server $server): ?int
    {
        $state = $task->stage_state ?? [];
        $delay = $this->guestScripts->checkReady($task, $server, $state);
        $task->rememberForStage($state);

        return $delay;
    }

    /**
     * Addresses, hostname and SSH keys into the guest; when its agent did
     * not answer, they follow at the next start through the panel.
     */
    private function refreshGuest(Task $task, Server $server): null
    {
        $this->refresh->run($task, $server->refresh(), waitForAgent: false);

        if (($task->input['grown'] ?? false) === true) {
            $this->guestScripts->run($task, $server, GuestScriptEvent::DiskResized);
        }

        return null;
    }

    private function wasRunning(Task $task): bool
    {
        return ($task->input['was_running'] ?? false) === true;
    }

    private function backup(Task $task): Backup
    {
        return Backup::query()
            ->where('server_id', $task->server_id)
            ->where('uuid', (string) ($task->input['backup'] ?? ''))
            ->first() ?? throw new RuntimeException('The backup no longer exists.');
    }
}
