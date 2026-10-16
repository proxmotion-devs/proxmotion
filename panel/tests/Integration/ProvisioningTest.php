<?php

namespace Tests\Integration;

use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Provisioning\NewServer;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * A server's whole life through the panel's own code on a real Proxmox:
 * create (clone → configure → grow disk → anti-spoofing → start), suspend,
 * unsuspend and terminate. Needs one spare public IPv4 address on the test
 * bridge (PROXMOX_TEST_IPV4 like 203.0.113.10/24, PROXMOX_TEST_IPV4_GATEWAY).
 */
class ProvisioningTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_a_server_is_created_suspended_unsuspended_and_terminated()
    {
        $order = $this->orderServer();
        $server = $this->provisionServer($order);

        $this->assertServerIsRunningAsConfigured($server, $order);

        $this->runTask($server, TaskType::Suspend);
        $this->assertSame(ServerStatus::Suspended, $server->refresh()->status);
        $this->assertSame('stopped', $this->vmStatus($server));

        $this->runTask($server, TaskType::Unsuspend);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame('running', $this->vmStatus($server));

        $this->runTask($server, TaskType::Terminate);
        $this->assertSame(ServerStatus::Terminated, $server->refresh()->status);
        $this->assertNull($this->guest($server->vmid), 'The VM was not deleted.');
        $this->assertSame(0, $server->ipAddresses()->count(), 'The address was not released.');
    }

    private function assertServerIsRunningAsConfigured(Server $server, NewServer $order): void
    {
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertGreaterThanOrEqual($this->vmidFrom(), $server->vmid);

        $guest = $this->guest($server->vmid);
        $this->assertNotNull($guest);
        $this->assertSame($this->pool(), $guest->pool, 'The server is not in the resource pool.');
        $this->assertSame('running', $guest->status);

        $address = (string) $server->ipAddresses()->value('address');
        $config = $this->client()->qemu($guest->node)->config($server->vmid) ?? [];

        $this->assertSame(1, (int) ($config['cores'] ?? 0));
        $this->assertSame(1024, (int) ($config['memory'] ?? 0));
        $this->assertStringContainsString("ip={$address}/", (string) ($config['ipconfig0'] ?? ''));
        $this->assertStringContainsString("gw={$this->setting('PROXMOX_TEST_IPV4_GATEWAY')}", (string) ($config['ipconfig0'] ?? ''));
        $this->assertStringContainsString('firewall=1', (string) ($config['net0'] ?? ''));
        $this->assertStringContainsString('enabled=1', (string) ($config['agent'] ?? ''));
        $this->assertSame('root', $config['ciuser'] ?? null);
        $this->assertStringContainsString('ssh-ed25519', rawurldecode((string) ($config['sshkeys'] ?? '')), 'The SSH key did not reach cloud-init.');
        $this->assertGreaterThanOrEqual($order->spec->diskMib, intdiv((int) $guest->diskBytes, 1024 * 1024));

        $this->assertAntiSpoofing($server, $guest->node, $address);
    }

    private function assertAntiSpoofing(Server $server, string $node, string $address): void
    {
        $base = "/nodes/{$node}/qemu/{$server->vmid}/firewall";
        $options = $this->client()->get("{$base}/options");
        $this->assertSame(1, (int) ($options['enable'] ?? 0), 'The VM firewall is off.');
        $this->assertSame(1, (int) ($options['ipfilter'] ?? 0), 'ipfilter is off.');
        $this->assertSame(1, (int) ($options['macfilter'] ?? 1), 'macfilter is off.');

        $entries = $this->client()->get("{$base}/ipset/ipfilter-net0");
        $ipset = array_column(is_array($entries) ? $entries : [], 'cidr');
        $this->assertContains($address, array_map(fn ($cidr) => explode('/', (string) $cidr)[0], $ipset), 'The address is not in the anti-spoofing ipset.');
    }
}
