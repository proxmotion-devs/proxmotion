<?php

namespace Tests\Feature\Provisioning;

use App\Enums\NodeStatus;
use App\Enums\ServerStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Placement\NodePlacer;
use App\Provisioning\Placement\Placement;
use App\Provisioning\Placement\PlacementRules;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class NodePlacerTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
    }

    private function place(): Placement
    {
        return app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr0');
    }

    public function test_the_least_used_node_is_chosen()
    {
        $pve2 = $this->addNode('pve2');
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);

        $placement = $this->place();

        $this->assertTrue($placement->node->is($pve2));
        $this->assertSame('local-lvm', $placement->storage->name);
    }

    public function test_the_template_on_the_same_node_is_preferred()
    {
        $this->assertSame(9000, $this->place()->template->vmid);
    }

    public function test_offline_and_maintenance_nodes_are_skipped()
    {
        $this->node->update(['is_maintenance' => true]);
        $this->expectNoCapacity();
    }

    public function test_offline_nodes_are_skipped()
    {
        $this->node->forceFill(['status' => NodeStatus::Offline])->save();

        $this->expectException(ProvisioningException::class);
        $this->place();
    }

    public function test_memory_over_commit_is_respected()
    {
        // 64 GiB node, 4 GiB package: 15 servers already booked leave room for one more.
        Server::factory()->count(15)->for($this->node)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);
        $this->place();

        Server::factory()->for($this->node)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);
        $this->expectNoCapacity();
    }

    public function test_terminated_servers_do_not_count()
    {
        Server::factory()->count(16)->for($this->node)->status(ServerStatus::Terminated)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);

        $this->assertTrue($this->place()->node->is($this->node));
    }

    public function test_cpu_over_commit_is_respected()
    {
        $this->node->update(['cpu_overcommit' => 1]);
        Server::factory()->count(8)->for($this->node)->create(['cpu_cores' => 2, 'memory_mib' => 512]);

        $this->expectNoCapacity();
    }

    public function test_max_servers_is_respected()
    {
        $this->node->update(['max_servers' => 1]);
        Server::factory()->for($this->node)->create(['memory_mib' => 512]);

        $this->expectNoCapacity();
    }

    public function test_storage_must_be_enabled_and_have_room()
    {
        $this->storage->update(['use_for_servers' => false]);
        $this->expectNoCapacity();
    }

    public function test_the_bridge_must_exist_on_the_node()
    {
        $this->expectException(ProvisioningException::class);

        app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr9');
    }

    public function test_an_os_template_without_a_proxmox_template_cannot_be_placed()
    {
        $this->osTemplate->proxmoxTemplates()->detach();

        $this->expectExceptionMessage('This OS template is not available on any cluster in this location.');

        $this->place();
    }

    public function test_the_package_clusters_decide_where_a_server_goes()
    {
        // A second, busier cluster in the same location, e.g. other CPUs.
        $first = $this->cluster;
        $this->cluster = Cluster::factory()->for($this->location)->create();
        $other = $this->addNode('r9-1');
        $this->osTemplate->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id, 'node_id' => $other->id, 'vmid' => 9100, 'name' => 'debian-12',
        ]));
        Server::factory()->for($other)->create(['memory_mib' => 32768]);
        $spec = ServerSpec::fromPackage($this->package);
        $placer = app(NodePlacer::class);

        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0')->node->is($this->node), 'Without clusters, the least used node anywhere.');
        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', new PlacementRules([$this->cluster->id]))->node->is($other));
        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', new PlacementRules([$first->id]))->node->is($this->node));

        $elsewhere = Cluster::factory()->create();
        $this->expectExceptionMessage('None of the clusters this package may use is in this location and has this OS template.');
        $placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', new PlacementRules([$elsewhere->id]));
    }

    public function test_server_creation_uses_the_package_clusters()
    {
        $this->package->clusters()->attach(Cluster::factory()->for($this->location)->create());

        $this->expectExceptionMessage('None of the clusters this package may use is in this location and has this OS template.');
        app(ServerCreator::class)->create($this->order());
    }

    private function placeOn(Node $node, ?string $storage = null): Placement
    {
        return app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr0', new PlacementRules(node: $node, storage: $storage));
    }

    public function test_an_admin_may_choose_the_node_instead_of_the_least_used_one()
    {
        $this->addNode('pve2');
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);

        $this->assertTrue($this->placeOn($this->node)->node->is($this->node));
    }

    public function test_a_chosen_node_may_be_in_maintenance_but_must_be_online()
    {
        $this->node->update(['is_maintenance' => true]);
        $this->assertTrue($this->placeOn($this->node)->node->is($this->node));

        $this->node->forceFill(['status' => NodeStatus::Offline])->save();
        $this->expectExceptionMessage('The chosen node is not online.');
        $this->placeOn($this->node);
    }

    public function test_a_chosen_storage_is_used_even_when_it_is_not_marked_for_servers()
    {
        $nvme = $this->node->storages()->forceCreate([
            'name' => 'nvme', 'type' => 'zfspool', 'content' => ['images'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => false, 'total_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
        ]);

        $this->assertSame('local-lvm', $this->placeOn($this->node)->storage->name, 'Without a storage, the one with the most room that is used for servers.');
        $this->assertTrue($this->placeOn($this->node, 'nvme')->storage->is($nvme));
    }

    public function test_a_chosen_storage_must_take_vm_disks_and_have_room()
    {
        $this->node->storages()->forceCreate([
            'name' => 'iso', 'type' => 'dir', 'content' => ['iso'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => false, 'total_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
        ]);
        $this->node->storages()->forceCreate([
            'name' => 'small', 'type' => 'lvmthin', 'content' => ['images'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => true, 'total_bytes' => 10 * 1024 ** 3, 'used_bytes' => 0,
        ]);

        foreach (['iso' => 'is not available for VM disks', 'missing' => 'is not available for VM disks', 'small' => 'does not have room'] as $name => $message) {
            try {
                $this->placeOn($this->node, $name);
                $this->fail("Storage {$name} should be refused.");
            } catch (ProvisioningException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_a_chosen_node_must_belong_to_the_location_fit_the_server_and_have_the_bridge()
    {
        $elsewhere = Node::factory()->create();
        $this->expectProvisioningError(fn () => $this->placeOn($elsewhere), 'The chosen node is not in an active cluster of this location.');

        $this->node->update(['max_servers' => 0]);
        $this->expectProvisioningError(fn () => $this->placeOn($this->node), 'The chosen node does not have enough free CPU, memory or server slots for this server.');
        $this->node->update(['max_servers' => null]);

        $this->expectProvisioningError(
            fn () => app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr9', new PlacementRules(node: $this->node)),
            'The chosen node does not have the bridge :bridge.',
        );

        $this->expectProvisioningError(
            fn () => app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr0', new PlacementRules([Cluster::factory()->for($this->location)->create()->id], node: $this->node)),
            'This package may not use the cluster of the chosen node.',
        );
    }

    public function test_server_creation_uses_the_chosen_node_and_storage()
    {
        $this->addNode('pve2');
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);
        $this->node->storages()->forceCreate([
            'name' => 'nvme', 'type' => 'zfspool', 'content' => ['images'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => false, 'total_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
        ]);
        (new FakeProxmoxCluster)->addTemplate(9000)->install();

        $server = app(ServerCreator::class)->create($this->order(['node' => $this->node, 'storage' => 'nvme']));

        $this->assertTrue($server->node->is($this->node));
        $this->assertSame('nvme', $server->storage?->name);
    }

    private function expectProvisioningError(\Closure $place, string $message): void
    {
        try {
            $place();
            $this->fail("Expected: {$message}");
        } catch (ProvisioningException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function expectNoCapacity(): void
    {
        $this->expectExceptionMessage('No node in this location has enough free capacity for this server.');

        $this->place();
    }
}
