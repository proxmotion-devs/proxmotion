<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Apps\AppStarter;
use App\Domain\Servers\GuestScripts;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\ProxmoxClient;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use RuntimeException;

/**
 * Clone → configure → grow disk → anti-spoofing → start.
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
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $server->forceFill(['status' => ServerStatus::Provisioning])->save();

        $this->build($task);

        // Additional addresses and the template's script, once the guest has booted (ADR 0009, 0010).
        $this->guestScripts->afterInstall($task, $server, waitForAgent: true);

        $server->forceFill(['status' => ServerStatus::Active, 'provisioned_at' => now()])->save();

        // The app it was ordered with (ADR 0004): never fails the server.
        $this->apps->startOrdered($task, $server, waitForAgent: true);
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

        $upid = $client->qemu($template->node->name)->clone(
            templateVmid: $template->vmid,
            newVmid: $server->vmid,
            name: $server->hostname,
            storage: $server->storage?->name,
            pool: $server->cluster->resource_pool,
            targetNode: $server->node->name,
        );

        $client->taskWaiter()->wait($upid, timeoutSeconds: 1800);
        $task->completeStep('clone');
    }

    /**
     * @return array<string, mixed> the configuration after the update
     */
    private function configure(Task $task, Server $server, QemuResource $qemu, ?string $previousNet0): array
    {
        $current = $qemu->config($server->vmid) ?? [];
        $net0 = $previousNet0 ?? (is_string($current['net0'] ?? null) ? $current['net0'] : null);

        $params = [
            'name' => $server->hostname,
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
        $task->completeStep('configure');

        return $qemu->config($server->vmid) ?? [];
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
