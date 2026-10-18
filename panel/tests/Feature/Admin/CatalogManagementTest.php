<?php

namespace Tests\Feature\Admin;

use App\Enums\NetworkModel;
use App\Enums\OsPlatform;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\ProxmoxTemplate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * OS templates and packages: what customers can buy and install.
 */
class CatalogManagementTest extends AdminTestCase
{
    public function test_os_templates_can_be_mapped_to_proxmox_templates()
    {
        $node = Node::factory()->create();
        $template = ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->id,
            'vmid' => 9000,
            'name' => 'ubuntu-2404',
        ]);

        $this->post(route('admin.os-templates.store'), [
            'name' => 'Ubuntu 24.04',
            'family' => 'ubuntu',
            'version' => '24.04',
            'is_active' => true,
            'sort_order' => 1,
            'proxmox_template_ids' => [$template->id],
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame([$template->id], $os->proxmoxTemplates()->pluck('proxmox_templates.id')->all());

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates.0.proxmox_template_ids', [$template->id])
                ->where('proxmoxTemplates.0.vmid', 9000));

        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Ubuntu 24.04 LTS',
            'family' => 'ubuntu',
            'version' => '24.04',
            'is_active' => false,
            'sort_order' => 1,
            'proxmox_template_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $os->proxmoxTemplates()->count());
        $this->assertFalse($os->refresh()->is_active);
    }

    public function test_the_platform_follows_the_family()
    {
        $this->post(route('admin.os-templates.store'), [
            'name' => 'Windows Server 2022',
            'family' => 'windows',
            'version' => '2022',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame(OsPlatform::Windows, $os->platform);

        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Debian 12',
            'family' => 'debian',
            'version' => '12',
            'is_active' => true,
            'platform' => 'windows',
        ])->assertSessionHasNoErrors();

        $this->assertSame(OsPlatform::Linux, $os->refresh()->platform);
    }

    public function test_templates_not_offered_yet_are_suggested_once_per_name()
    {
        $first = Node::factory()->create(['name' => 'pve1']);
        $second = Node::factory()->for($first->cluster)->create(['name' => 'pve2']);
        $template = fn (Node $node, int $vmid, string $name) => ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $node->cluster_id, 'node_id' => $node->id, 'vmid' => $vmid, 'name' => $name,
        ]);
        $ubuntu = [$template($first, 9000, 'ubuntu-2404-cloud')->id, $template($second, 9001, 'ubuntu-2404-cloud')->id];
        $mapped = $template($first, 9002, 'debian-12');
        OsTemplate::factory()->create()->proxmoxTemplates()->attach($mapped);

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('found', 1)
                ->where('found.0.name', 'ubuntu-2404-cloud')
                ->where('found.0.proxmox_template_ids', $ubuntu)
                ->where('found.0.guess', ['name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'version' => '24.04'])
                ->has('found.0.places', 2)
                ->has('proxmoxTemplates', 3));
    }

    public function test_os_template_family_is_validated()
    {
        $this->post(route('admin.os-templates.store'), [
            'name' => 'X',
            'family' => 'beos',
            'version' => '1',
            'is_active' => true,
            'sort_order' => 0,
        ])->assertSessionHasErrors('family');
    }

    public function test_packages_store_friendly_units_as_mib_and_bytes()
    {
        $this->post(route('admin.packages.store'), [
            'name' => 'VPS M',
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 80,
            'traffic_gb' => 2000,
            'port_speed_mbps' => null,
            'ipv4_count' => 1,
            'ipv6_count' => 1,
            'snapshot_slots' => 2,
            'backup_slots' => 1,
            'network_models' => ['bridged', 'routed'],
            'is_active' => true,
            'sort_order' => 0,
        ])->assertSessionHasNoErrors();

        $package = Package::query()->sole();
        $this->assertSame(80 * 1024, $package->disk_mib);
        $this->assertSame(2000 * 1000 ** 3, $package->traffic_bytes);
        $this->assertNull($package->port_speed_mbps);
        $this->assertEquals([NetworkModel::Bridged, NetworkModel::Routed], $package->network_models->all());
    }

    public function test_unlimited_traffic_is_stored_as_null()
    {
        $package = Package::factory()->create();

        $this->put(route('admin.packages.update', $package), [
            ...$package->only(['name', 'cpu_cores', 'memory_mib', 'ipv4_count', 'ipv6_count', 'snapshot_slots', 'backup_slots', 'sort_order']),
            'disk_gib' => 40,
            'traffic_gb' => null,
            'port_speed_mbps' => 100,
            'network_models' => ['nat'],
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull($package->refresh()->traffic_bytes);
    }

    public function test_packages_need_at_least_one_network_model()
    {
        $this->post(route('admin.packages.store'), [
            'name' => 'X', 'cpu_cores' => 1, 'memory_mib' => 512, 'disk_gib' => 10,
            'ipv4_count' => 1, 'ipv6_count' => 0, 'snapshot_slots' => 0, 'backup_slots' => 0,
            'network_models' => [], 'is_active' => true, 'sort_order' => 0,
        ])->assertSessionHasErrors('network_models');
    }

    public function test_dashboard_shows_counts()
    {
        Node::factory()->count(2)->for(Cluster::factory())->create();

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.clusters', 1)
                ->where('stats.nodes', 2)
                ->where('stats.nodes_online', 2));
    }
}
