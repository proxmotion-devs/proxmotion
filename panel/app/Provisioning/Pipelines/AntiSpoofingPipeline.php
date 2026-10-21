<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Infrastructure\FirewallChecker;
use App\Enums\ServerOrigin;
use App\Models\Task;
use App\Provisioning\Network\GuestIsolation;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Turns anti-spoofing on (again) for a server whose VM firewall does not
 * protect it: adopted VMs, or a firewall changed in Proxmox (docs/adr/0033).
 * Only the VM firewall changes: options, the DHCP server rules, an IP set of
 * exactly the server's addresses, net0's firewall flag and the isolation IP
 * sets and rules (docs/adr/0034). Adopted VMs keep
 * their firewall policies; nothing runs inside the guest. Safe to run again.
 */
final readonly class AntiSpoofingPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private FirewallChecker $firewall,
        private GuestIsolation $isolation,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);
        $addresses = $this->network->allowedAddresses($server);

        $qemu->enableAntiSpoofing($server->vmid, $addresses, setPolicies: $server->origin === ServerOrigin::Provisioned);
        $qemu->removeFromAntiSpoofing($server->vmid, array_values(array_diff($qemu->antiSpoofingAddresses($server->vmid), $addresses)));
        $qemu->enableInterfaceFirewall($server->vmid);
        $this->isolation->apply($qemu, $server, always: true);

        // Read it back at once, so the page shows the result (and the drift closes) now, not after the next sync.
        $this->firewall->recheck($server, $qemu);
        $task->completeStep('firewall');
    }
}
