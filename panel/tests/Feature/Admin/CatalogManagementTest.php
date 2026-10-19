<?php

namespace Tests\Feature\Admin;

use App\Enums\NetworkModel;
use App\Enums\OsFamily;
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
            'distribution' => 'ubuntu',
            'version' => '24.04',
            'is_active' => true,
            'sort_order' => 1,
            'proxmox_template_ids' => [$template->id],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'OS template created. Create a server from it, then use Check against a server to confirm the system.');

        $os = OsTemplate::query()->sole();
        $this->assertSame([$template->id], $os->proxmoxTemplates()->pluck('proxmox_templates.id')->all());

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates.0.proxmox_template_ids', [$template->id])
                ->where('templates.0.family', 'debian')
                ->where('proxmoxTemplates.0.vmid', 9000)
                ->where('distributions.1.id', 'ubuntu')
                ->where('distributions.1.family', 'debian')
                ->where('distributions.1.releases.1.version', '24.04'));

        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Ubuntu 24.04 LTS',
            'distribution' => 'ubuntu',
            'version' => '24.04',
            'is_active' => false,
            'sort_order' => 1,
            'proxmox_template_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $os->proxmoxTemplates()->count());
        $this->assertFalse($os->refresh()->is_active);
    }

    public function test_the_family_and_platform_follow_a_known_distribution()
    {
        $this->post(route('admin.os-templates.store'), [
            'name' => 'Windows Server 2022',
            'distribution' => 'windows',
            'version' => '2022',
            'family' => 'debian',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame([OsFamily::Windows, OsPlatform::Windows], [$os->family, $os->platform]);

        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Rocky Linux 9',
            'distribution' => 'Rocky',
            'version' => '9',
            'is_active' => true,
            'platform' => 'windows',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['rocky', OsFamily::Rhel, OsPlatform::Linux], [$os->refresh()->distribution, $os->family, $os->platform]);
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
                ->where('found.0.guess', ['name' => 'Ubuntu 24.04 LTS', 'distribution' => 'ubuntu', 'version' => '24.04', 'compatible_with' => null, 'compatible_version' => null])
                ->has('found.0.places', 2)
                ->has('proxmoxTemplates', 3));
    }

    public function test_an_unknown_distribution_is_of_the_family_other_unless_one_is_given()
    {
        $post = fn (array $attributes) => $this->post(route('admin.os-templates.store'), $attributes + [
            'name' => 'Zorin OS 17',
            'distribution' => 'zorin',
            'version' => '17',
            'is_active' => true,
        ]);

        $post(['family' => 'beos'])->assertSessionHasErrors('family');
        $post(['family' => 'windows'])->assertSessionHasErrors('family');
        $post(['distribution' => 'my distro'])->assertSessionHasErrors('distribution');
        $this->assertSame(0, OsTemplate::query()->count());

        $post([])->assertSessionHasNoErrors();
        $this->assertSame(OsFamily::Other, OsTemplate::query()->sole()->family);
        OsTemplate::query()->delete();

        // A check against a server may have found its family.
        $post(['family' => 'debian'])->assertSessionHasNoErrors();
        $this->assertSame(['zorin', OsFamily::Debian, null], [OsTemplate::query()->sole()->distribution, OsTemplate::query()->sole()->family, OsTemplate::query()->sole()->compatible_with]);
    }

    public function test_a_known_derivative_is_matched_as_its_base_from_the_catalog()
    {
        $post = fn (array $attributes) => $this->post(route('admin.os-templates.store'), $attributes + [
            'name' => 'Pardus 23.4',
            'distribution' => ' Pardus ',
            'version' => '23.4',
            'is_active' => true,
        ]);

        $post(['version' => '19'])->assertSessionHasErrors('version');
        $this->assertSame(0, OsTemplate::query()->count());

        // What the form sends about the base is ignored: the catalog decides.
        $post(['compatible_with' => 'almalinux', 'compatible_version' => '9'])->assertSessionHasNoErrors();
        $os = OsTemplate::query()->sole();
        $this->assertSame(['pardus', '23.4', OsFamily::Debian, 'debian', '12'], [$os->distribution, $os->version, $os->family, $os->compatible_with, $os->compatible_version]);

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates.0.compatible_with', 'debian')
                ->where('templates.0.compatible_version', '12'));

        // A base system is matched as itself.
        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Debian 12',
            'distribution' => 'debian',
            'version' => '12',
            'compatible_with' => 'ubuntu',
            'compatible_version' => '24.04',
            'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull($os->refresh()->compatible_with);
    }

    public function test_rhel_takes_the_almalinux_version()
    {
        $this->post(route('admin.os-templates.store'), [
            'name' => 'RHEL 9.6',
            'distribution' => 'rhel',
            'version' => '9.6',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame([OsFamily::Rhel, 'almalinux', '9.6'], [$os->family, $os->compatible_with, $os->compatible_version]);
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
