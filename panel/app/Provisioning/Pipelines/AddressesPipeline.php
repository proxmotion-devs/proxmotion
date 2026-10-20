<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Infrastructure\FirewallChecker;
use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\ServerOrigin;
use App\Models\IpAddress;
use App\Models\Task;
use App\Provisioning\IpAllocator;
use App\Provisioning\Network\NetworkConfig;
use App\Provisioning\VmLabels;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Applies the server's addresses as the panel records them: a new primary
 * (PrimaryAddressChanger, docs/adr/0011), an edit or "apply again"
 * (AddressChanger, docs/adr/0028). Anti-spoofing (the server's addresses in,
 * released ones out) → release → the address guest script, which configures
 * the primary, the default route and the additional addresses inside the
 * guest; the VM's notes and address tags follow (VmLabels). cloud-init's
 * ipconfig0 is deliberately left alone: changing it makes cloud-init treat the
 * next boot as a new instance. Safe to run again.
 */
final readonly class AddressesPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private IpAllocator $ips,
        private GuestScripts $guestScripts,
        private FirewallChecker $firewall,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);

        $releaseIds = array_map('intval', (array) ($task->input['release_address_ids'] ?? []));
        $releasing = IpAddress::query()->whereIn('id', $releaseIds)->where('server_id', $server->id)->get();
        $released = array_values($releasing->map(fn (IpAddress $address) => $address->address)->all());
        $keep = array_values(array_diff($this->network->allowedAddresses($server), $released));

        $reapply = ($task->input['reapply'] ?? false) === true;
        $qemu->enableAntiSpoofing($server->vmid, $keep, setPolicies: $server->origin === ServerOrigin::Provisioned);

        // "Apply again" makes the IP set exactly the server's addresses (docs/adr/0028 §3)
        // and turns net0's firewall flag back on (docs/adr/0033).
        $remove = $reapply
            ? array_values(array_diff($qemu->antiSpoofingAddresses($server->vmid), $keep))
            : $released;
        $qemu->removeFromAntiSpoofing($server->vmid, $remove);

        if ($reapply) {
            $qemu->enableInterfaceFirewall($server->vmid);
            // Read it back at once: a firewall drift closes now, not after the next sync.
            $this->firewall->recheck($server, $qemu);
        }

        // Only now, out of the firewall, may another server get it.
        $this->ips->releaseAddresses($releasing);
        $task->completeStep('addresses', VmLabels::apply($qemu, $server));

        $this->guestScripts->run($task, $server, GuestScriptEvent::AddressesChanged);
    }
}
