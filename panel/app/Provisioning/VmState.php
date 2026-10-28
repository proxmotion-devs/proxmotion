<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Infrastructure\FirewallChecker;
use App\Models\Server;
use App\Provisioning\Network\NetworkConfig;
use App\Provisioning\Network\ServerFirewall;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\Resources\QemuResource;
use App\Proxmox\TaskWaiter;
use RuntimeException;

/**
 * Writes what the panel records about a server into its VM: resources and
 * port speed, the boot disk's size, and the firewall with exactly the
 * server's addresses. Used by resource changes and after a snapshot
 * rollback, which brings back the configuration of its time
 * (docs/adr/0051 §5). Cloud-init settings are never written here: changing
 * them gives the server a new cloud-init instance and new SSH host keys.
 * Every step looks at Proxmox first, so running it again is safe.
 */
final readonly class VmState
{
    public function __construct(
        private NetworkConfig $network,
        private ServerFirewall $firewallRules,
        private FirewallChecker $firewall,
    ) {}

    /**
     * Cores, memory, net0 (port speed, same MAC and bridge) and the CPU and disk limits.
     */
    public function configure(Server $server, QemuResource $qemu): void
    {
        $current = $qemu->config($server->vmid) ?? throw new RuntimeException("VM {$server->vmid} does not exist.");
        $params = ['cores' => $server->cpu_cores, 'sockets' => 1, 'memory' => $server->memory_mib];
        // net0 carries the port speed; the MAC address and bridge stay the same.
        $network = $this->network->proxmoxParams($server, is_string($current['net0'] ?? null) ? $current['net0'] : null);

        if (isset($network['net0'])) {
            $params['net0'] = $network['net0'];
        }

        // CPU and disk limits (docs/adr/0034); Proxmox applies them to a running VM at once.
        $limits = ResourceLimits::of($server)->proxmoxConfig($current);
        $qemu->updateConfig($server->vmid, [...$params, ...$limits['params']], $limits['delete']);

        // Fills it in for servers created before the panel recorded MAC addresses.
        $server->forceFill(['mac_address' => $this->network->macAddress($current) ?? $server->mac_address])->save();
    }

    /**
     * Grow the boot disk to the server's size; Proxmox never shrinks one.
     *
     * @return bool whether it grew
     */
    public function growDisk(Server $server, QemuResource $qemu, TaskWaiter $waiter): bool
    {
        $disk = BootDisk::fromConfig($qemu->config($server->vmid) ?? []) ?? throw new RuntimeException('Could not find the boot disk of the VM.');

        if ($disk->sizeMib >= $server->disk_mib) {
            return false;
        }

        $upid = $qemu->resize($server->vmid, $disk->key, $server->disk_mib);

        if ($upid !== null) {
            $waiter->wait($upid, timeoutSeconds: 600);
        }

        return true;
    }

    /**
     * The firewall as the panel records it: anti-spoofing with exactly the
     * server's addresses, the isolation sets and the panel's rules, and
     * net0's firewall flag (docs/adr/0033–0035).
     */
    public function firewall(Server $server, QemuResource $qemu): void
    {
        $keep = $this->network->allowedAddresses($server);
        $qemu->enableAntiSpoofing($server->vmid, $keep);
        $qemu->removeFromAntiSpoofing($server->vmid, array_values(array_diff($qemu->antiSpoofingAddresses($server->vmid), $keep)));
        $this->firewallRules->apply($qemu, $server, always: true);
        $qemu->enableInterfaceFirewall($server->vmid);
        // Read it back at once: a firewall drift closes now, not after the next sync.
        $this->firewall->recheck($server, $qemu);
    }
}
