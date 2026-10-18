<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestAddresses;
use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\PowerAction;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\IpAllocator;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\Resources\QemuResource;
use RuntimeException;

/**
 * Applies a server's new resources (recorded by ResourceChanger) to its VM:
 * cores, memory and port speed → grow the disk → anti-spoofing addresses
 * (new ones in, released ones out) → restart if asked → additional addresses
 * inside the guest (ADR 0009) → file system grown inside the guest (ADR 0010).
 * Every step looks at Proxmox first, so running it again is safe.
 *
 * Cores and memory of a running VM wait for its next start in Proxmox
 * ("pending"); the server is flagged reboot_required until then.
 */
final readonly class ChangeResourcesPipeline
{
    /** Configuration keys whose pending change needs a restart of the VM. */
    private const RESTART_KEYS = ['cores', 'sockets', 'memory', 'net0'];

    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private IpAllocator $ips,
        private PowerPipeline $power,
        private GuestScripts $guestScripts,
        private GuestAddresses $guestAddresses,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $client = $this->clients->forCluster($server->cluster);
        $qemu = $client->qemu($server->node->name);
        $client->taskWaiter()->waitForVm($server->node->name, $server->vmid);

        $this->configure($task, $server, $qemu);
        $this->growDisk($task, $server, $qemu);
        $this->updateAddresses($task, $server, $qemu);
        $restarted = $this->apply($task, $server, $qemu);

        // Additional addresses inside the guest (ADR 0009); also removes released ones there.
        // A server without any, e.g. after a disk-only upgrade, has nothing to configure.
        if ($this->guestAddresses->additional($server) !== [] || ($task->input['release_address_ids'] ?? []) !== []) {
            $this->guestScripts->run($task, $server, GuestScriptEvent::AddressesChanged, waitForAgent: $restarted);
        }

        // The partition and file system to the new disk size (ADR 0010).
        if (($task->input['grow_disk'] ?? false) === true) {
            $this->guestScripts->run($task, $server, GuestScriptEvent::DiskResized);
        }
    }

    private function configure(Task $task, Server $server, QemuResource $qemu): void
    {
        $current = $qemu->config($server->vmid) ?? throw new RuntimeException("VM {$server->vmid} does not exist.");
        $params = ['cores' => $server->cpu_cores, 'sockets' => 1, 'memory' => $server->memory_mib];

        // net0 carries the port speed; the MAC address and bridge stay the same.
        $network = $this->network->proxmoxParams($server, is_string($current['net0'] ?? null) ? $current['net0'] : null);

        if (isset($network['net0'])) {
            $params['net0'] = $network['net0'];
        }

        $qemu->updateConfig($server->vmid, $params);

        // Fills it in for servers created before the panel recorded MAC addresses.
        $server->forceFill(['mac_address' => $this->network->macAddress($current) ?? $server->mac_address])->save();
        $task->completeStep('configure');
    }

    private function growDisk(Task $task, Server $server, QemuResource $qemu): void
    {
        $disk = BootDisk::fromConfig($qemu->config($server->vmid) ?? []) ?? throw new RuntimeException('Could not find the boot disk of the VM.');

        if ($disk->sizeMib < $server->disk_mib) {
            $upid = $qemu->resize($server->vmid, $disk->key, $server->disk_mib);

            if ($upid !== null) {
                $this->clients->forCluster($server->cluster)->taskWaiter()->wait($upid, timeoutSeconds: 600);
            }
        }

        $task->completeStep('resize');
    }

    private function updateAddresses(Task $task, Server $server, QemuResource $qemu): void
    {
        $releaseIds = array_map('intval', (array) ($task->input['release_address_ids'] ?? []));
        $releasing = IpAddress::query()->whereIn('id', $releaseIds)->where('server_id', $server->id)->get();
        $released = array_values($releasing->map(fn (IpAddress $address) => $address->address)->all());
        $keep = array_values(array_diff($this->network->allowedAddresses($server), $released));

        $qemu->enableAntiSpoofing($server->vmid, $keep);
        $qemu->removeFromAntiSpoofing($server->vmid, $released);

        // Only now, out of the firewall, may another server get them.
        $this->ips->releaseAddresses($releasing);
        $task->completeStep('addresses');
    }

    /**
     * @return bool whether the VM was restarted
     */
    private function apply(Task $task, Server $server, QemuResource $qemu): bool
    {
        $waiting = array_intersect(array_keys($qemu->pendingChanges($server->vmid)), self::RESTART_KEYS);
        $restarted = false;

        if ($waiting !== [] && ($task->input['reboot'] ?? false) === true) {
            $this->power->power($task, PowerAction::Reboot);
            $waiting = array_intersect(array_keys($qemu->pendingChanges($server->vmid)), self::RESTART_KEYS);
            $restarted = true;
        }

        $server->forceFill(['reboot_required' => $waiting !== []])->save();
        $task->completeStep('apply', $waiting !== [] ? 'The new CPU and memory take effect when the server is restarted.' : null);

        return $restarted;
    }
}
