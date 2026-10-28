<?php

namespace Tests\Feature\Admin;

use App\Enums\NetworkModel;
use App\Enums\OsFamily;
use App\Enums\OsPlatform;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\OperatingSystem;
use App\Models\OperatingSystemVersion;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\ProxmoxTemplate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * OS templates and packages: what customers can buy and install.
 */
class CatalogManagementTest extends AdminTestCase
{
    /** A version of the administrators' list (docs/adr/0026). */
    private function systemVersion(string $name, string $version, string $platform = 'linux'): OperatingSystemVersion
    {
        $system = OperatingSystem::query()->firstOrCreate(['name' => $name], ['platform' => $platform]);

        return $system->versions()->create(['version' => $version]);
    }

    public function test_os_templates_can_be_mapped_to_proxmox_templates()
    {
        $node = Node::factory()->create();
        $template = ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->id,
            'vmid' => 9000,
            'name' => 'ubuntu-2404',
        ]);
        $noble = $this->systemVersion('Ubuntu', '24.04');

        $this->post(route('admin.os-templates.store'), [
            'name' => 'Ubuntu 24.04',
            'operating_system_version_id' => $noble->id,
            'is_active' => true,
            'sort_order' => 1,
            'proxmox_template_ids' => [$template->id],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast.message', 'OS template created. Create a server from it, then use Check against a server to confirm the system.');

        $os = OsTemplate::query()->sole();
        $this->assertSame([$template->id], $os->proxmoxTemplates()->pluck('proxmox_templates.id')->all());
        $this->assertSame(['ubuntu', '24.04', OsFamily::Debian, OsPlatform::Linux], [$os->distribution, $os->version, $os->family, $os->platform]);

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('templates.0.proxmox_template_ids', [$template->id])
                ->where('templates.0.operating_system_version_id', $noble->id)
                ->where('proxmoxTemplates.0.vmid', 9000)
                ->where('systems.0.name', 'Ubuntu')
                ->where('systems.0.versions.0', ['id' => $noble->id, 'version' => '24.04', 'templates' => 1]));

        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Ubuntu 24.04 LTS',
            'operating_system_version_id' => $noble->id,
            'is_active' => false,
            'sort_order' => 1,
            'proxmox_template_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $os->proxmoxTemplates()->count());
        $this->assertFalse($os->refresh()->is_active);
    }

    public function test_a_template_takes_the_system_version_and_platform_of_its_version()
    {
        $this->post(route('admin.os-templates.store'), ['name' => 'Windows', 'is_active' => true])
            ->assertSessionHasErrors('operating_system_version_id');
        $this->post(route('admin.os-templates.store'), ['name' => 'Windows', 'operating_system_version_id' => 999, 'is_active' => true])
            ->assertSessionHasErrors('operating_system_version_id');

        $this->post(route('admin.os-templates.store'), [
            'name' => 'Windows Server 2022',
            'operating_system_version_id' => $this->systemVersion('Windows Server', '2022', 'windows')->id,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame(['windows-server', '2022', OsFamily::Windows, OsPlatform::Windows], [$os->distribution, $os->version, $os->family, $os->platform]);

        // Any system administrators add; an unknown one is of no family.
        $this->put(route('admin.os-templates.update', $os), [
            'name' => 'Zorin OS 17',
            'operating_system_version_id' => $this->systemVersion('Zorin OS', '17')->id,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['zorin-os', '17', OsFamily::Other, OsPlatform::Linux], [$os->refresh()->distribution, $os->version, $os->family, $os->platform]);
    }

    public function test_templates_not_offered_yet_are_suggested_once_per_name()
    {
        $first = Node::factory()->create(['name' => 'pve1']);
        $second = Node::factory()->for($first->cluster)->create(['name' => 'pve2']);
        $template = fn (Node $node, int $vmid, string $name) => ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $node->cluster_id, 'node_id' => $node->id, 'vmid' => $vmid, 'name' => $name,
        ]);
        $ubuntu = [$template($first, 9000, 'ubuntu-2404-cloud')->id, $template($second, 9001, 'ubuntu-2404-cloud')->id];
        $template($first, 9003, 'rocky-linux-9.5');
        $mapped = $template($first, 9002, 'debian-12');
        OsTemplate::factory()->create(['distribution' => 'debian', 'version' => '12'])->proxmoxTemplates()->attach($mapped);
        $noble = $this->systemVersion('Ubuntu', '24.04');
        $rocky = $this->systemVersion('Rocky Linux', '9');

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('found', 2)
                ->where('found.0.name', 'ubuntu-2404-cloud')
                ->where('found.0.proxmox_template_ids', $ubuntu)
                ->where('found.0.guess', ['name' => 'Ubuntu 24.04 LTS', 'operating_system_version_id' => $noble->id])
                ->has('found.0.places', 2)
                // Rocky Linux is recognised by its name; 9.5 is a point release of 9.
                ->where('found.1.guess.operating_system_version_id', $rocky->id)
                ->has('proxmoxTemplates', 4));
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

    public function test_packages_store_cpu_and_disk_limits()
    {
        // docs/adr/0034
        $this->post(route('admin.packages.store'), [
            'name' => 'VPS S', 'cpu_cores' => 2, 'memory_mib' => 2048, 'disk_gib' => 40,
            'ipv4_count' => 1, 'ipv6_count' => 0, 'snapshot_slots' => 0, 'backup_slots' => 0,
            'network_models' => ['bridged'], 'is_active' => true, 'sort_order' => 0,
            'cpu_limit' => 1.25, 'cpu_units' => null, 'disk_mb_s' => 200, 'disk_iops' => '',
        ])->assertSessionHasNoErrors();

        $package = Package::query()->sole();
        $this->assertSame(['1.25', null, 200, null], [$package->cpu_limit, $package->cpu_units, $package->disk_mb_s, $package->disk_iops]);

        $this->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page->where('packages.0.cpu_limit', 1.25)->where('packages.0.disk_mb_s', 200));
    }

    public function test_packages_store_a_reinstall_limit()
    {
        // docs/adr/0045
        $payload = [
            'name' => 'VPS S', 'cpu_cores' => 2, 'memory_mib' => 2048, 'disk_gib' => 40,
            'ipv4_count' => 1, 'ipv6_count' => 0, 'snapshot_slots' => 0, 'backup_slots' => 0,
            'network_models' => ['bridged'], 'is_active' => true, 'sort_order' => 0,
        ];

        $this->post(route('admin.packages.store'), [...$payload, 'reinstall_limit' => 3])->assertSessionHasErrors(['reinstall_period_days']);
        $this->post(route('admin.packages.store'), [...$payload, 'reinstall_limit' => 3, 'reinstall_period_days' => 30])->assertSessionHasNoErrors();

        $package = Package::query()->sole();
        $this->assertSame([3, 30], [$package->reinstall_limit, $package->reinstall_period_days]);
        $this->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page->where('packages.0.reinstall_limit', 3)->where('packages.0.reinstall_period_days', 30));

        // Unlimited drops the window too.
        $this->put(route('admin.packages.update', $package), [...$payload, 'reinstall_limit' => null, 'reinstall_period_days' => 30])->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$package->refresh()->reinstall_limit, $package->reinstall_period_days]);
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
