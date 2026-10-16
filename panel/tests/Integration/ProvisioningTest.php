<?php

namespace Tests\Integration;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Domain\Networking\AddIpAddresses;
use App\Enums\NetworkModel;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\NewServer;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use App\Support\Network\Cidr;
use Illuminate\Support\Str;
use Tests\Unit\PublicKeyTest;

/**
 * A server's whole life through the panel's own code on a real Proxmox:
 * create (clone → configure → grow disk → anti-spoofing → start), suspend,
 * unsuspend and terminate. Needs one spare public IPv4 address on the test
 * bridge (PROXMOX_TEST_IPV4 like 203.0.113.10/24, PROXMOX_TEST_IPV4_GATEWAY).
 */
class ProvisioningTest extends IntegrationTestCase
{
    public function test_a_server_is_created_suspended_unsuspended_and_terminated()
    {
        if ($this->setting('PROXMOX_TEST_IPV4') === '' || $this->setting('PROXMOX_TEST_IPV4_GATEWAY') === '') {
            $this->markTestSkipped('Set PROXMOX_TEST_IPV4 and PROXMOX_TEST_IPV4_GATEWAY to a spare address on the test bridge.');
        }

        $order = $this->prepareOrder();
        $server = app(ServerCreator::class)->create($order);

        $this->assertServerIsRunningAsConfigured($server->refresh(), $order);

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

    /**
     * Sync the cluster, then set up what an admin would: a storage for
     * servers, an IP pool with the spare address, an OS template and a customer.
     */
    private function prepareOrder(): NewServer
    {
        app(ClusterSynchronizer::class)->sync($this->cluster);
        $this->cluster->refresh();
        config(['proxmotion.provisioning.min_vmid' => $this->vmidFrom()]);

        $node = $this->cluster->nodes()->where('name', $this->node())->firstOrFail();
        $node->storages()->where('name', $this->setting('PROXMOX_TEST_STORAGE'))->firstOrFail()
            ->forceFill(['use_for_servers' => true])->save();

        [$address, $prefix] = explode('/', $this->setting('PROXMOX_TEST_IPV4')) + [1 => '32'];
        $pool = IpPool::query()->create([
            'location_id' => $this->cluster->location_id,
            'name' => 'integration-test-v4',
            'version' => 4,
            'network_model' => NetworkModel::Bridged,
            'network' => (string) Cidr::parse("{$address}/{$prefix}"),
            'gateway' => $this->setting('PROXMOX_TEST_IPV4_GATEWAY'),
            'bridge' => $this->setting('PROXMOX_TEST_BRIDGE'),
            'nameservers' => [],
            'is_active' => true,
        ]);
        app(AddIpAddresses::class)->handle($pool, [$address]);
        $this->assertSame(1, $pool->addresses()->count(), "{$address} could not be added (is it the gateway or a node address?).");

        $template = $this->cluster->proxmoxTemplates()->where('vmid', $this->templateVmid())->firstOrFail();
        $osTemplate = OsTemplate::factory()->create(['name' => 'Integration test OS']);
        $osTemplate->proxmoxTemplates()->attach($template);

        // Never smaller than the template: disks only grow.
        $templateMib = intdiv((int) $this->guest($this->templateVmid())?->diskBytes, 1024 * 1024);

        return new NewServer(
            owner: User::factory()->create(),
            location: $this->cluster->location,
            spec: new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: max(10240, $templateMib), ipv4Count: 1, ipv6Count: 0),
            osTemplate: $osTemplate,
            networkModel: NetworkModel::Bridged,
            hostname: 'proxmotion-integration.example.com',
            rootPassword: Str::password(20, symbols: false),
            sshKeys: PublicKeyTest::ED25519,
        );
    }

    private function assertServerIsRunningAsConfigured(Server $server, NewServer $order): void
    {
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
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
        $this->assertGreaterThanOrEqual($order->spec->diskMib, intdiv((int) $this->guest($server->vmid)?->diskBytes, 1024 * 1024));

        $base = "/nodes/{$guest->node}/qemu/{$server->vmid}/firewall";
        $options = $this->client()->get("{$base}/options");
        $this->assertSame(1, (int) ($options['enable'] ?? 0), 'The VM firewall is off.');
        $this->assertSame(1, (int) ($options['ipfilter'] ?? 0), 'ipfilter is off.');
        $this->assertSame(1, (int) ($options['macfilter'] ?? 1), 'macfilter is off.');

        $entries = $this->client()->get("{$base}/ipset/ipfilter-net0");
        $ipset = array_column(is_array($entries) ? $entries : [], 'cidr');
        $this->assertContains($address, array_map(fn ($cidr) => explode('/', (string) $cidr)[0], $ipset), 'The address is not in the anti-spoofing ipset.');
    }

    private function runTask(Server $server, TaskType $type): void
    {
        $task = app(TaskRunner::class)->start($server, $type);

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status, "{$type->value}: ".$task->error);
    }

    private function vmStatus(Server $server): ?string
    {
        return $this->guest($server->vmid)?->status;
    }
}
