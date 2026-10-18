<?php

namespace Tests\Feature\Admin;

use App\Models\Location;
use App\Models\Node;
use App\Models\Package;
use App\Models\PlacementGroup;
use App\Models\Server;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;

/**
 * Placement groups in the admin panel (docs/adr/0020).
 */
class PlacementGroupManagementTest extends AdminTestCase
{
    use BuildsInfrastructure;

    private Node $pve2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->pve2 = $this->addNode('pve2');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'NVMe EPYC',
            'description' => '',
            'location' => $this->location->uuid,
            'node_ids' => [$this->node->id],
            'storage_ids' => [],
            ...$overrides,
        ];
    }

    public function test_groups_can_be_created_listed_updated_and_deleted()
    {
        $this->post(route('admin.placement-groups.store'), $this->payload(['storage_ids' => [$this->storage->id]]))
            ->assertSessionHasNoErrors();

        $group = PlacementGroup::query()->sole();
        $this->assertSame([$this->node->id], $group->nodes()->pluck('nodes.id')->all());
        $this->assertSame([$this->storage->id], $group->storages()->pluck('storages.id')->all());

        $this->get(route('admin.placement-groups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/placement-groups/Index')
                ->where('groups.0.name', 'NVMe EPYC')
                ->where('groups.0.node_ids', [$this->node->id])
                ->has('nodes', 2));

        $this->put(route('admin.placement-groups.update', $group), $this->payload(['node_ids' => [$this->pve2->id]]))
            ->assertSessionHasNoErrors();
        $this->assertSame([$this->pve2->id], $group->nodes()->pluck('nodes.id')->all());
        $this->assertSame(0, $group->storages()->count());

        $this->delete(route('admin.placement-groups.destroy', $group));
        $this->assertModelMissing($group);
    }

    public function test_nodes_and_storages_must_fit_the_location()
    {
        $elsewhere = Node::factory()->create();
        $this->post(route('admin.placement-groups.store'), $this->payload(['node_ids' => [$elsewhere->id]]))
            ->assertSessionHasErrors('node_ids');

        $otherStorage = $this->pve2->storages()->firstOrFail();
        $this->post(route('admin.placement-groups.store'), $this->payload(['storage_ids' => [$otherStorage->id]]))
            ->assertSessionHasErrors('storage_ids');

        $iso = $this->node->storages()->forceCreate([
            'name' => 'iso', 'type' => 'dir', 'content' => ['iso'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => false, 'total_bytes' => 1024 ** 3, 'used_bytes' => 0,
        ]);
        $this->post(route('admin.placement-groups.store'), $this->payload(['storage_ids' => [$iso->id]]))
            ->assertSessionHasErrors('storage_ids');

        $this->post(route('admin.placement-groups.store'), $this->payload(['node_ids' => []]))
            ->assertSessionHasErrors('node_ids');
    }

    public function test_a_group_a_package_uses_cannot_be_deleted_nor_its_location()
    {
        $group = PlacementGroup::factory()->for($this->location)->create();
        $this->package->placementGroups()->attach($group);

        $this->delete(route('admin.placement-groups.destroy', $group));
        $this->assertModelExists($group);

        $empty = Location::factory()->create();
        PlacementGroup::factory()->for($empty)->create();
        $this->delete(route('admin.locations.destroy', $empty));
        $this->assertModelExists($empty);
    }

    public function test_packages_can_name_placement_groups()
    {
        $group = PlacementGroup::factory()->for($this->location)->create();
        $package = Package::factory()->create();
        $data = [
            ...$package->only(['name', 'cpu_cores', 'memory_mib', 'ipv4_count', 'ipv6_count', 'snapshot_slots', 'backup_slots', 'sort_order']),
            'disk_gib' => 40,
            'network_models' => ['bridged'],
            'is_active' => true,
        ];

        $this->put(route('admin.packages.update', $package), [...$data, 'placement_group_ids' => [$group->id]])->assertSessionHasNoErrors();

        $this->assertSame([$group->id], $package->placementGroups()->pluck('placement_groups.id')->all());
        $this->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages', fn ($packages) => collect($packages)->firstWhere('uuid', $package->uuid)['placement_group_ids'] === [$group->id])
                ->has('placementGroups', 1));
    }

    public function test_a_server_can_be_created_in_a_placement_group()
    {
        (new FakeProxmoxCluster)->addTemplate(9000)->install();
        // pve1 is busier, so automatic placement would take pve2.
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);
        $group = PlacementGroup::factory()->for($this->location)->create();
        $group->nodes()->attach($this->node);

        $this->get(route('admin.servers.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('placementGroups.0.value', $group->uuid)
                ->where('nodes.0.placement_groups', [$group->uuid]));

        $this->post(route('admin.servers.store'), [
            'location' => $this->location->uuid,
            'resource_mode' => 'package',
            'package' => $this->package->uuid,
            'os_template' => $this->osTemplate->uuid,
            'network_model' => 'bridged',
            'hostname' => 'web1.example.com',
            'placement' => 'group',
            'placement_group' => $group->uuid,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Server::query()->where('hostname', 'web1.example.com')->sole()->node->is($this->node));
    }
}
