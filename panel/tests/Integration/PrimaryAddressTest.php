<?php

namespace Tests\Integration;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\User;
use App\Provisioning\PrimaryAddressChanger;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * ADR 0011 on a real guest: the address script moves the primary address and
 * the default route without touching cloud-init, keeps it across a reboot,
 * and removes a released primary. Documentation addresses (192.0.2.0/24) on a
 * clone without network access to them; nothing leaves the VM.
 */
class PrimaryAddressTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_the_primary_moves_inside_the_guest()
    {
        $server = $this->adoptTestVm('proxmotion-primary-test');
        $node = $server->node->name;
        $client = $this->client();
        $client->qemu($node)->updateConfig($server->vmid, ['ipconfig0' => 'ip=192.0.2.10/24,gw=192.0.2.1']);
        $this->runTask($server, TaskType::Start);
        $this->waitForAgent($server->vmid, $node, 600);

        $pool = IpPool::query()->create([
            'location_id' => $this->cluster->location_id,
            'name' => 'integration-primary',
            'version' => 4,
            'network_model' => NetworkModel::Bridged,
            'network' => '192.0.2.0/24',
            'gateway' => '192.0.2.1',
            'bridge' => $this->setting('PROXMOX_TEST_BRIDGE'),
            'nameservers' => [],
            'is_active' => true,
        ]);
        foreach (['192.0.2.10' => $server->id, '192.0.2.11' => $server->id, '192.0.2.12' => null] as $address => $serverId) {
            $ip = new IpAddress;
            $ip->forceFill(['ip_pool_id' => $pool->id, 'address' => $address, 'server_id' => $serverId, 'assigned_at' => $serverId === null ? null : now()])->save();
        }

        $agent = $client->guestAgent($node);
        $addresses = fn () => $agent->run($server->vmid, ['ip', '-o', '-4', 'addr', 'show', 'scope', 'global'])->stdout;
        $route = fn () => $agent->run($server->vmid, ['ip', '-4', 'route', 'show', 'default'])->stdout;
        $changer = app(PrimaryAddressChanger::class);
        $admin = User::factory()->create();
        $ipconfig = $client->qemu($node)->config($server->vmid)['ipconfig0'] ?? null;

        // Swap: the additional address becomes the primary, the old one stays.
        $task = $changer->request($server->refresh(), IpVersion::V4, '192.0.2.11', $admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $step = collect($task->steps)->firstWhere('key', 'guest_addresses');
        $this->assertNotNull($step, (string) json_encode($task->steps));
        $this->assertNull($step['message'], (string) $step['message']);
        $this->assertStringContainsString('src 192.0.2.11', $route(), 'The default route does not use the new primary.');
        $this->assertStringContainsString('192.0.2.10/24', $addresses(), 'The old primary must stay as an additional address.');
        $this->assertSame($ipconfig, $client->qemu($node)->config($server->vmid)['ipconfig0'] ?? null, 'cloud-init settings were changed.');

        // Kept across a reboot by the systemd unit.
        $this->runTask($server, TaskType::Reboot);
        $this->waitForAgent($server->vmid, $node, 600);
        $this->assertStringContainsString('src 192.0.2.11', $route(), 'The primary did not survive the reboot.');

        // Replaced by a free address: the old primary is released and removed inside the guest.
        $task = $changer->request($server->refresh(), IpVersion::V4, '192.0.2.12', $admin, null)->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertStringContainsString('src 192.0.2.12', $route());
        $this->assertStringNotContainsString('192.0.2.11/', $addresses(), 'The released primary is still configured.');
        $this->assertStringContainsString('192.0.2.10/24', $addresses());
    }
}
