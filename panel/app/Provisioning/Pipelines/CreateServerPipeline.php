<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppStarter;
use App\Domain\Servers\GuestScripts;
use App\Enums\CloneMode;
use App\Enums\GuestScriptEvent;
use App\Enums\OsPlatform;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\LinkedClones;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use RuntimeException;

/**
 * Clone → configure → grow disk → anti-spoofing → start → wait for the guest agent.
 *
 * Every step checks Proxmox's actual state first, so running the pipeline
 * again after a failure continues where it stopped instead of starting over.
 */
final readonly class CreateServerPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private GuestScripts $guestScripts,
        private AppStarter $apps,
        private LinkedClones $linkedClones,
        private PowerPipeline $power,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $server->forceFill(['status' => ServerStatus::Provisioning])->save();

        $this->build($task);

        // Active only once the new system's guest agent answers, and on Windows once
        // setup has finished (or the wait times out, ADR 0004).
        $this->guestScripts->waitForAgentStep($task, $server, setup: true);

        // A server named from its location's template: its hostname inside the guest (ADR 0031).
        $this->nameGuest($task, $server);

        // Additional addresses and the template's script (ADR 0009, 0010).
        $this->guestScripts->afterInstall($task, $server);

        $server->forceFill(['status' => ServerStatus::Active, 'provisioned_at' => now()])->save();

        // The app it was ordered with (ADR 0004): never fails the server.
        $this->apps->startOrdered($task, $server);
    }

    /**
     * A server named from its location's template has its address as VM name
     * (docs/adr/0031), from which cloud-init and Cloudbase-Init took the
     * hostname inside the guest: the hostname script sets the real one. Windows
     * applies it at its next restart, done here. A failing script leaves a note.
     */
    public function nameGuest(Task $task, Server $server): void
    {
        if ($server->vm_name === null || $server->vm_name === $server->hostname) {
            return;
        }

        if (! $this->guestScripts->run($task, $server, GuestScriptEvent::HostnameChanged)
            || $this->guestScripts->platform($server) !== OsPlatform::Windows) {
            return;
        }

        $this->power->power($task, PowerAction::Reboot);
        $this->guestScripts->waitForAgent($server);
    }

    /**
     * Build the VM from the server's OS template and start it. Reinstalling
     * runs this too, after deleting the old VM.
     *
     * @param  string|null  $previousNet0  net0 of a deleted VM, to keep its MAC address
     */
    public function build(Task $task, ?string $previousNet0 = null): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);

        $this->cloneVm($task, $server, $client, $qemu);
        $config = $this->configure($task, $server, $qemu, $previousNet0);
        $this->growDisk($task, $server, $client, $qemu, $config);

        $qemu->enableAntiSpoofing($server->vmid, $this->network->allowedAddresses($server));
        $task->completeStep('firewall');

        if ($qemu->status($server->vmid)['status'] !== 'running') {
            $client->taskWaiter()->wait($qemu->power($server->vmid, 'start'), timeoutSeconds: 300);
        }

        $task->completeStep('start');
    }

    private function cloneVm(Task $task, Server $server, ProxmoxClient $client, QemuResource $qemu): void
    {
        if ($qemu->config($server->vmid) !== null) {
            $task->completeStep('clone', 'VM already exists.');

            return;
        }

        $template = $server->osTemplate?->proxmoxTemplates()
            ->where('cluster_id', $server->cluster_id)
            ->orderByRaw('node_id = ? desc', [$server->node_id])
            ->with('node')
            ->first() ?? throw new RuntimeException('The OS template has no Proxmox template on this cluster.');

        $source = $client->qemu($template->node->name);
        $clone = fn (bool $full) => $source->clone(
            templateVmid: $template->vmid,
            newVmid: $server->vmid,
            name: $server->vmName(),
            storage: $server->storage?->name,
            pool: $server->cluster->resource_pool,
            targetNode: $server->node->name,
            full: $full,
        );

        // Linked clones when the template asks for them and the storage allows it,
        // otherwise a full clone with the reason on the step (docs/adr/0027).
        $message = null;
        $upid = null;

        if ($server->osTemplate->clone_mode === CloneMode::Linked) {
            $reason = $this->linkedClones->unavailableReason($server, $template, $source->config($template->vmid) ?? []);

            if ($reason === null) {
                try {
                    $upid = $clone(false);
                    $message = "Linked clone of template {$template->vmid}.";
                } catch (ProxmoxRequestFailed $e) {
                    $reason = "Proxmox refused a linked clone: {$e->getMessage()}";
                }
            }

            $message ??= "Full clone: {$reason}";
        }

        $client->taskWaiter()->wait($upid ?? $clone(true), timeoutSeconds: 1800);
        $task->completeStep('clone', $message);
    }

    /**
     * @return array<string, mixed> the configuration after the update
     */
    private function configure(Task $task, Server $server, QemuResource $qemu, ?string $previousNet0): array
    {
        $current = $qemu->config($server->vmid) ?? [];
        $net0 = $previousNet0 ?? (is_string($current['net0'] ?? null) ? $current['net0'] : null);

        $params = [
            'name' => $server->vmName(),
            'description' => "Managed by Proxmotion. Server {$server->uuid}",
            'cores' => $server->cpu_cores,
            'sockets' => 1,
            'memory' => $server->memory_mib,
            'onboot' => 1,
            'agent' => 'enabled=1',
            'ciuser' => 'root',
            'ciupgrade' => 0,
            ...$this->network->proxmoxParams($server, $net0),
        ];

        if ($server->root_password !== null) {
            $params['cipassword'] = $server->root_password;
        }

        if ($server->ssh_keys !== null && trim($server->ssh_keys) !== '') {
            // Proxmox expects the keys URL-encoded inside the (form-encoded) request.
            $params['sshkeys'] = rawurlencode(trim($server->ssh_keys));
        }

        $qemu->updateConfig($server->vmid, $params);

        // Proxmox generates the MAC address on clone; record the one the VM really has.
        $config = $qemu->config($server->vmid) ?? [];
        $server->forceFill(['mac_address' => $this->network->macAddress($config)])->save();
        $task->completeStep('configure');

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function growDisk(Task $task, Server $server, ProxmoxClient $client, QemuResource $qemu, array $config): void
    {
        $disk = BootDisk::fromConfig($config) ?? throw new RuntimeException('Could not find the boot disk of the VM.');

        if ($disk->sizeMib < $server->disk_mib) {
            $upid = $qemu->resize($server->vmid, $disk->key, $server->disk_mib);

            if ($upid !== null) {
                $client->taskWaiter()->wait($upid, timeoutSeconds: 600);
            }
        }

        $task->completeStep('resize');
    }
}
