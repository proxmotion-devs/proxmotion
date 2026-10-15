<?php

namespace Tests\Concerns;

use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\Cluster;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\ProxmoxTemplate;
use App\Models\Storage;
use App\Models\User;
use App\Provisioning\NewServer;

/**
 * A ready-to-use location: one cluster with node "pve1", local-lvm storage,
 * bridge vmbr0, Ubuntu template 9000, a package and a bridged IPv4 /29 pool.
 */
trait BuildsInfrastructure
{
    protected Location $location;

    protected Cluster $cluster;

    protected Node $node;

    protected Storage $storage;

    protected OsTemplate $osTemplate;

    protected Package $package;

    protected IpPool $ipv4Pool;

    protected User $customer;

    protected function buildInfrastructure(): void
    {
        $this->location = Location::factory()->create(['code' => 'ist1']);
        $this->cluster = Cluster::factory()->for($this->location)->create(['resource_pool' => 'proxmotion']);
        $this->node = $this->addNode('pve1');
        $this->storage = $this->node->storages()->firstOrFail();

        $this->osTemplate = OsTemplate::factory()->create();
        $this->osTemplate->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->id,
            'vmid' => 9000,
            'name' => 'ubuntu-2404-cloudinit',
        ]));

        $this->package = Package::factory()->create([
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_mib' => 40960,
            'ipv4_count' => 1,
            'ipv6_count' => 0,
            'port_speed_mbps' => 1000,
            'network_models' => [NetworkModel::Bridged, NetworkModel::Routed],
        ]);

        $this->ipv4Pool = IpPool::factory()->for($this->location)->create([
            'network' => '203.0.113.0/29',
            'gateway' => '203.0.113.1',
            'nameservers' => ['192.0.2.53'],
        ]);
        app(GenerateIpAddresses::class)->handle($this->ipv4Pool);

        $this->customer = User::factory()->create();
    }

    protected function addNode(string $name, int $memoryGib = 64, int $cores = 16): Node
    {
        $node = Node::factory()->for($this->cluster)->create([
            'name' => $name,
            'cpu_cores' => $cores,
            'memory_bytes' => $memoryGib * 1024 ** 3,
        ]);

        $node->storages()->forceCreate([
            'name' => 'local-lvm',
            'type' => 'lvmthin',
            'content' => ['images', 'rootdir'],
            'is_shared' => false,
            'is_available' => true,
            'use_for_servers' => true,
            'total_bytes' => 500 * 1024 ** 3,
            'used_bytes' => 0,
        ]);
        $node->bridges()->forceCreate(['name' => 'vmbr0', 'is_active' => true]);

        return $node;
    }

    protected function addIpv6Pool(): IpPool
    {
        return IpPool::factory()->for($this->location)->ipv6()->create([
            'version' => IpVersion::V6,
            'network' => '2001:db8:100::/64',
            'gateway' => '2001:db8:100::1',
        ]);
    }

    protected function order(array $overrides = []): NewServer
    {
        return new NewServer(...[
            'owner' => $this->customer,
            'location' => $this->location,
            'package' => $this->package,
            'osTemplate' => $this->osTemplate,
            'networkModel' => NetworkModel::Bridged,
            'hostname' => 'web1.example.com',
            'rootPassword' => 'a-very-long-password',
            ...$overrides,
        ]);
    }
}
