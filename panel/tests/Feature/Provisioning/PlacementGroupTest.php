<?php

namespace Tests\Feature\Provisioning;

use App\Models\Cluster;
use App\Models\Location;
use App\Models\Node;
use App\Models\PlacementGroup;
use App\Models\Server;
use App\Models\Storage;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Placement groups (docs/adr/0020): named sets of nodes and storages that
 * packages and new servers target.
 */
class PlacementGroupTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Node $pve2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->pve2 = $this->addNode('pve2');
        // pve1 is busier, so automatic placement prefers pve2.
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);
        (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    private function group(array $nodes, array $storages = []): PlacementGroup
    {
        $group = PlacementGroup::factory()->for($this->location)->create();
        $group->nodes()->attach(array_map(fn (Node $node) => $node->id, $nodes));
        $group->storages()->attach(array_map(fn (Storage $storage) => $storage->id, $storages));

        return $group;
    }

    private function nvme(Node $node, bool $useForServers = false): Storage
    {
        return $node->storages()->forceCreate([
            'name' => 'nvme', 'type' => 'zfspool', 'content' => ['images'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => $useForServers, 'total_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
        ]);
    }

    private function create(array $overrides = []): Server
    {
        return app(ServerCreator::class)->create($this->order($overrides));
    }

    public function test_a_chosen_group_keeps_the_server_on_its_nodes()
    {
        $server = $this->create(['placementGroup' => $this->group([$this->node])]);

        $this->assertTrue($server->node->is($this->node), 'pve2 is less used but not in the group.');
        $this->assertSame('local-lvm', $server->storage?->name);
    }

    public function test_a_group_with_storages_uses_only_those_even_when_they_are_not_marked_for_servers()
    {
        $nvme = $this->nvme($this->node);

        $server = $this->create(['placementGroup' => $this->group([$this->node, $this->pve2], [$nvme])]);

        $this->assertTrue($server->node->is($this->node), 'pve2 has none of the group\'s storages.');
        $this->assertTrue($server->storage?->is($nvme));
    }

    public function test_nodes_in_maintenance_are_skipped_inside_a_group()
    {
        $this->node->update(['is_maintenance' => true]);

        $this->expectExceptionMessage('No node of the placement group has enough free capacity and a usable storage for this server.');
        $this->create(['placementGroup' => $this->group([$this->node])]);
    }

    public function test_a_group_of_another_location_is_refused()
    {
        $group = PlacementGroup::factory()->create();

        $this->expectExceptionMessage('The chosen placement group is not in this location.');
        $this->create(['placementGroup' => $group]);
    }

    public function test_package_groups_decide_where_its_servers_go()
    {
        $this->package->placementGroups()->attach($this->group([$this->node]));

        $this->assertTrue($this->create()->node->is($this->node));
    }

    public function test_a_package_without_a_group_in_the_location_cannot_be_ordered_there()
    {
        $this->package->placementGroups()->attach(PlacementGroup::factory()->for(Location::factory())->create());

        $this->expectExceptionMessage('None of the placement groups this package may use is in this location.');
        $this->create();
    }

    public function test_an_admin_may_only_choose_a_group_or_node_the_package_allows()
    {
        $allowed = $this->group([$this->node]);
        $this->package->placementGroups()->attach($allowed);

        foreach ([
            'This package may not use the chosen placement group.' => ['placementGroup' => $this->group([$this->pve2])],
            'The chosen node is not in a placement group this package may use.' => ['node' => $this->pve2],
        ] as $message => $overrides) {
            try {
                $this->create($overrides);
                $this->fail("Expected: {$message}");
            } catch (ProvisioningException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertTrue($this->create(['placementGroup' => $allowed])->node->is($this->node));
    }

    public function test_package_clusters_still_apply_inside_groups()
    {
        $this->package->placementGroups()->attach($this->group([$this->node]));
        $this->package->clusters()->attach(Cluster::factory()->for($this->location)->create());

        $this->expectException(ProvisioningException::class);
        $this->create();
    }
}
