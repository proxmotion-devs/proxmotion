<?php

namespace Tests\Integration\Concerns;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Domain\Migration\AdoptServer;
use App\Domain\Networking\AddIpAddresses;
use App\Enums\NetworkModel;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Provisioning\NewServer;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use App\Support\Network\Cidr;
use Illuminate\Support\Str;
use Tests\Integration\IntegrationTestCase;
use Tests\Unit\PublicKeyTest;

/**
 * Panel servers on the test Proxmox: provisioned through ServerCreator (needs
 * the spare address PROXMOX_TEST_IPV4), or adopted from a clone of the test
 * template (needs no address). tearDown deletes their VMs.
 *
 * @mixin IntegrationTestCase
 */
trait ManagesTestServers
{
    /**
     * Skip the test unless a spare IPv4 address for provisioning is configured.
     */
    protected function requireSpareIpv4(): void
    {
        if ($this->setting('PROXMOX_TEST_IPV4') === '' || $this->setting('PROXMOX_TEST_IPV4_GATEWAY') === '') {
            $this->markTestSkipped('Set PROXMOX_TEST_IPV4 and PROXMOX_TEST_IPV4_GATEWAY to a spare address on the test bridge.');
        }
    }

    /**
     * Create a server through the panel and assert its create task succeeded.
     */
    protected function provisionServer(?NewServer $order = null): Server
    {
        $server = app(ServerCreator::class)->create($order ?? $this->orderServer());
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, 'create: '.$task->error);

        return $server->refresh();
    }

    /**
     * Sync the cluster, then set up what an admin would: a storage for
     * servers, an IP pool with the spare address and an OS template.
     */
    protected function orderServer(): NewServer
    {
        $this->requireSpareIpv4();
        $this->syncCluster();
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
            location: $this->cluster->location,
            spec: new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: max(10240, $templateMib), ipv4Count: 1, ipv6Count: 0),
            osTemplate: $osTemplate,
            networkModel: NetworkModel::Bridged,
            hostname: 'proxmotion-integration.example.com',
            rootPassword: Str::password(20, symbols: false),
            sshKeys: PublicKeyTest::ED25519,
        );
    }

    /**
     * Clone the test template and adopt the clone as an active server
     * without addresses. The VM stays stopped.
     */
    protected function adoptTestVm(string $name): Server
    {
        $vmid = $this->cloneTestVm($name);
        $this->syncCluster();

        return app(AdoptServer::class)->adopt($this->cluster, $vmid, null, NetworkModel::Bridged, []);
    }

    protected function syncCluster(): void
    {
        app(ClusterSynchronizer::class)->sync($this->cluster);
        $this->cluster->refresh();
    }

    /**
     * Run a server task (the test queue is synchronous) and assert it succeeded.
     */
    protected function runTask(Server $server, TaskType $type): Task
    {
        $task = app(TaskRunner::class)->start($server, $type);

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status, "{$type->value}: ".$task->error);

        return $task;
    }

    /**
     * The live power state (status/current), or null when the VM is gone.
     * /cluster/resources lags behind by up to ~10 seconds.
     */
    protected function vmStatus(Server $server): ?string
    {
        if ($this->guest($server->vmid) === null) {
            return null;
        }

        return $this->client()->qemu($server->node->name)->status($server->vmid)['status'];
    }
}
