<?php

declare(strict_types=1);

namespace App\Provisioning\Pipelines;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Models\IpAddress;
use App\Models\Task;
use App\Provisioning\IpAllocator;
use App\Provisioning\Network\NetworkConfig;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Applies a new primary address (recorded by PrimaryAddressChanger, docs/adr/0011):
 * anti-spoofing (new address in, released one out) → release → the address
 * guest script, which moves the primary and the default route inside the
 * guest. cloud-init's ipconfig0 is deliberately left alone: changing it makes
 * cloud-init treat the next boot as a new instance. Safe to run again.
 */
final readonly class PrimaryAddressPipeline
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private NetworkConfig $network,
        private IpAllocator $ips,
        private GuestScripts $guestScripts,
    ) {}

    public function run(Task $task): void
    {
        $server = $task->server;
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);

        $releaseIds = array_map('intval', (array) ($task->input['release_address_ids'] ?? []));
        $releasing = IpAddress::query()->whereIn('id', $releaseIds)->where('server_id', $server->id)->get();
        $released = array_values($releasing->map(fn (IpAddress $address) => $address->address)->all());
        $keep = array_values(array_diff($this->network->allowedAddresses($server), $released));

        $qemu->enableAntiSpoofing($server->vmid, $keep);
        $qemu->removeFromAntiSpoofing($server->vmid, $released);

        // Only now, out of the firewall, may another server get it.
        $this->ips->releaseAddresses($releasing);
        $task->completeStep('addresses');

        $this->guestScripts->run($task, $server, GuestScriptEvent::AddressesChanged);
    }
}
