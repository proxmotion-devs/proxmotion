<?php

namespace Tests\Integration;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Enums\NetworkModel;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\Task;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * ADR 0009 and 0010 on a real guest: the default script configures
 * additional addresses, keeps them across a reboot (systemd unit) and removes released ones. Uses
 * documentation addresses (192.0.2.0/24) on a clone without network access to
 * them; nothing leaves the VM.
 */
class GuestAddressesTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_additional_addresses_are_configured_kept_and_removed()
    {
        $server = $this->adoptTestVm('proxmotion-addresses-test');
        $this->runTask($server, TaskType::Start);
        $node = $server->node->name;
        $this->waitForAgent($server->vmid, $node, 600);

        // A primary and an additional address of a documentation network.
        $pool = IpPool::query()->create([
            'location_id' => $this->cluster->location_id,
            'name' => 'integration-addresses',
            'version' => 4,
            'network_model' => NetworkModel::Bridged,
            'network' => '192.0.2.0/24',
            'gateway' => '192.0.2.1',
            'bridge' => $this->setting('PROXMOX_TEST_BRIDGE'),
            'nameservers' => [],
            'is_active' => true,
        ]);
        foreach (['192.0.2.10', '192.0.2.11'] as $address) {
            $ip = new IpAddress;
            $ip->forceFill(['ip_pool_id' => $pool->id, 'address' => $address, 'server_id' => $server->id, 'assigned_at' => now()])->save();
        }

        $guest = app(GuestScripts::class);
        $agent = $this->client()->guestAgent($node);
        $addresses = fn () => $agent->run($server->vmid, ['ip', '-o', 'addr', 'show'])->stdout;

        $task = $this->task($server);
        $guest->run($task, $server, GuestScriptEvent::AddressesChanged);
        $this->assertNull($task->refresh()->steps[0]['message'] ?? null, (string) json_encode($task->steps));
        $this->assertStringContainsString('192.0.2.11/24', $addresses(), 'The additional address is not configured.');
        $this->assertStringNotContainsString('192.0.2.10/', $addresses(), 'The primary address is cloud-init\'s job.');

        // Still there after a reboot (proxmotion-addresses.service).
        $this->runTask($server, TaskType::Reboot);
        $this->waitForAgent($server->vmid, $node, 600);
        $this->assertStringContainsString('192.0.2.11/24', $addresses(), 'The address did not survive the reboot.');

        // Released: removed inside the guest too.
        IpAddress::query()->where('address', '192.0.2.11')->update(['server_id' => null]);
        $guest->run($this->task($server), $server, GuestScriptEvent::AddressesChanged);
        $this->assertStringNotContainsString('192.0.2.11/', $addresses(), 'The released address is still configured.');
    }

    private function task(Server $server): Task
    {
        $task = new Task;
        $task->forceFill(['server_id' => $server->id, 'type' => TaskType::ChangeResources, 'status' => TaskStatus::Succeeded, 'steps' => []])->save();

        return $task;
    }
}
