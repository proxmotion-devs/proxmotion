<?php

namespace Tests\Feature\Admin;

use App\Domain\Apps\RecipeSynchronizer;
use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\AppEntitlementSource;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\BillingSystem;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\PlacementGroup;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\Unit\PublicKeyTest;

class ServerManagementTest extends AdminTestCase
{
    use BuildsInfrastructure;

    private FakeProxmoxCluster $proxmox;

    private PlacementGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->group = PlacementGroup::factory()->for($this->location)->create(['name' => 'General']);
        $this->group->nodes()->attach($this->node);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'location' => $this->location->uuid,
            // The package's values, as the form fills them in.
            'cpu_cores' => 2, 'memory_mib' => 4096, 'disk_gib' => 40, 'ipv4_count' => 1, 'ipv6_count' => 0, 'port_speed_mbps' => 1000, 'traffic_gb' => 2000,
            'package' => $this->package->uuid,
            'os_template' => $this->osTemplate->uuid,
            'network_model' => 'bridged',
            'hostname' => 'Web1.Example.com',
            'placement' => 'group',
            'placement_group' => $this->group->uuid,
            'root_password' => '',
            'ssh_keys' => '',
            ...$overrides,
        ];
    }

    public function test_an_admin_can_create_a_server()
    {
        $response = $this->post(route('admin.servers.store'), $this->payload());

        $server = Server::query()->sole();
        $response->assertRedirect(route('admin.servers.show', $server));
        $this->assertSame('web1.example.com', $server->hostname);
        $this->assertNull($server->external_id);
        $this->assertSame(20, strlen((string) $server->root_password), 'A random password is generated.');
        $this->assertSame(ServerStatus::Active, $server->status);

        $this->get(route('admin.servers.show', $server))
            ->assertOk()
            ->assertDontSee((string) $server->root_password)
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/servers/Show')
                ->where('server.status', 'active')
                ->where('server.addresses.0.address', '203.0.113.2')
                ->has('tasks', 1));
    }

    public function test_an_admin_can_choose_the_node_and_storage()
    {
        $pve2 = $this->addNode('pve2');
        $pve2->storages()->forceCreate([
            'name' => 'nvme', 'type' => 'zfspool', 'content' => ['images'], 'is_shared' => false,
            'is_available' => true, 'use_for_servers' => false, 'total_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
        ]);
        $this->osTemplate->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id, 'node_id' => $pve2->id, 'vmid' => 9001, 'name' => 'ubuntu-2404-cloudinit',
        ]));
        Server::factory()->for($pve2)->create(['memory_mib' => 32768]);
        $this->proxmox->addTemplate(9001, 'pve2');

        $this->get(route('admin.servers.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages.0.placement_groups', [])
                ->where('nodes.1.value', $pve2->uuid)
                ->where('nodes.1.cluster', $this->cluster->uuid)
                ->where('nodes.1.location', $this->location->uuid)
                ->where('nodes.1.storages.1.name', 'nvme')
                ->where('nodes.1.storages.1.free_mib', 100 * 1024));

        $this->post(route('admin.servers.store'), $this->payload(['placement' => 'node', 'node' => $pve2->uuid, 'storage' => 'nvme']))
            ->assertSessionHasNoErrors();

        $server = Server::query()->where('hostname', 'web1.example.com')->sole();
        $this->assertTrue($server->node->is($pve2));
        $this->assertSame('nvme', $server->storage?->name);

        $this->post(route('admin.servers.store'), $this->payload(['hostname' => 'web2.example.com', 'placement' => 'node', 'node' => $pve2->uuid, 'storage' => 'missing']))
            ->assertSessionHasErrors('server');
        $this->post(route('admin.servers.store'), $this->payload(['placement' => 'node']))->assertSessionHasErrors('node');
    }

    public function test_the_create_form_names_the_placement_groups_a_package_may_use()
    {
        $this->package->placementGroups()->attach($this->group);

        $this->get(route('admin.servers.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages.0.placement_groups', [$this->group->uuid])
                ->missing('packages.0.clusters'));

        // A node outside them is still refused when the form is bypassed.
        $pve2 = $this->addNode('pve2');
        $this->post(route('admin.servers.store'), $this->payload(['placement' => 'node', 'node' => $pve2->uuid]))
            ->assertSessionHasErrors(['server' => __('The chosen node is not in a placement group this package may use.')]);
    }

    public function test_a_placement_group_or_a_single_node_must_be_chosen()
    {
        $this->post(route('admin.servers.store'), $this->payload(['placement' => null]))->assertSessionHasErrors('placement');
        $this->post(route('admin.servers.store'), $this->payload(['placement' => 'auto']))->assertSessionHasErrors('placement');
        $this->post(route('admin.servers.store'), $this->payload(['placement_group' => null]))->assertSessionHasErrors('placement_group');
        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_ipv4_pool_and_addresses_can_be_chosen()
    {
        $this->get(route('admin.servers.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packages.0.ipv4_count', 1)
                ->where('ipv4Pools.0.value', $this->ipv4Pool->uuid)
                ->where('ipv4Pools.0.location', $this->location->uuid)
                ->where('ipv4Pools.0.network_model', 'bridged')
                ->where('ipv4Pools.0.addresses', ['203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5', '203.0.113.6'])
                ->where('ipv4Pools.0.free', 5));

        // Three addresses: two ticked (the first is primary), the third the next free one.
        $this->post(route('admin.servers.store'), $this->payload([
            'package' => null,
            'cpu_cores' => 1,
            'memory_mib' => 1024,
            'disk_gib' => 10,
            'ipv4_count' => 3,
            'ipv6_count' => 0,
            'ipv4_pool' => $this->ipv4Pool->uuid,
            'ipv4_addresses' => ['203.0.113.5', '203.0.113.3'],
        ]))->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $addresses = $server->ipAddresses()->get();
        $this->assertSame(['203.0.113.2', '203.0.113.3', '203.0.113.5'], $addresses->pluck('address')->sort()->values()->all());
        $this->assertTrue($addresses->firstWhere('address', '203.0.113.5')?->is_primary);
        $this->assertStringContainsString('ip=203.0.113.5/29', (string) $this->proxmox->vms[$server->vmid]['config']['ipconfig0']);
    }

    public function test_chosen_ipv4_addresses_must_be_free_addresses_of_the_pool_and_fit_the_server()
    {
        $elsewhere = IpPool::factory()->create(['network' => '198.51.100.0/29', 'gateway' => '198.51.100.1']);
        app(GenerateIpAddresses::class)->handle($elsewhere);
        IpAddress::query()->where('address', '203.0.113.4')->update(['is_reserved' => true]);

        $pool = $this->ipv4Pool->name;

        foreach ([
            __('The IPv4 pool :pool is not an active pool of this location for the chosen network model.', ['pool' => $elsewhere->name]) => ['ipv4_pool' => $elsewhere->uuid],
            __(':address is not a free address of the pool :pool.', ['address' => '198.51.100.2', 'pool' => $pool]) => ['ipv4_pool' => $this->ipv4Pool->uuid, 'ipv4_addresses' => ['198.51.100.2']],
            __(':address is not a free address of the pool :pool.', ['address' => '203.0.113.4', 'pool' => $pool]) => ['ipv4_pool' => $this->ipv4Pool->uuid, 'ipv4_addresses' => ['203.0.113.4']],
            __('This server gets only :count IPv4 addresses.', ['count' => '1']) => ['ipv4_pool' => $this->ipv4Pool->uuid, 'ipv4_addresses' => ['203.0.113.2', '203.0.113.3']],
        ] as $message => $overrides) {
            $this->post(route('admin.servers.store'), $this->payload($overrides))->assertSessionHasErrors(['server' => $message]);
        }

        $this->post(route('admin.servers.store'), $this->payload(['ipv4_addresses' => ['203.0.113.2', '203.0.113.2']]))
            ->assertSessionHasErrors('ipv4_addresses.0');
        $this->assertSame(0, Server::query()->count());
    }

    public function test_package_values_fill_the_form_and_stay_editable()
    {
        $this->get(route('admin.servers.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/servers/Create')
                ->where('packages.0.cpu_cores', 2)
                ->where('packages.0.memory_mib', 4096)
                ->where('packages.0.disk_gib', 40)
                ->where('packages.0.port_speed_mbps', 1000)
                ->where('packages.0.traffic_gb', 2000)
                // 16 cores with an over-commit of 4.
                ->where('nodes.0.capacity.cpu_cores', 64)
                ->where('nodes.0.capacity.servers', null)
                ->where('placementGroups.0.storages', []));

        // The package with more memory and disk, and unlimited port speed.
        $this->post(route('admin.servers.store'), $this->payload([
            'memory_mib' => 8192,
            'disk_gib' => 80,
            'port_speed_mbps' => null,
        ]))->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertTrue($server->package?->is($this->package), 'The server keeps the package link.');
        $this->assertSame(2, $server->cpu_cores);
        $this->assertSame(8192, $server->memory_mib);
        $this->assertSame(80 * 1024, $server->disk_mib);
        $this->assertNull($server->port_speed_mbps);
        $this->assertSame(2000 * 1000 ** 3, $server->traffic_bytes);
    }

    public function test_resources_are_required_even_with_a_package()
    {
        $this->post(route('admin.servers.store'), $this->payload(['cpu_cores' => null, 'disk_gib' => null]))
            ->assertSessionHasErrors(['cpu_cores', 'disk_gib']);
        $this->assertDatabaseCount('servers', 0);
    }

    public function test_a_server_can_be_created_with_custom_resources_and_no_package()
    {
        $this->post(route('admin.servers.store'), $this->payload([
            'package' => null,
            'cpu_cores' => 3,
            'memory_mib' => 3072,
            'disk_gib' => 30,
            'ipv4_count' => 1,
            'ipv6_count' => 0,
            'port_speed_mbps' => 200,
            'traffic_gb' => null,
        ]))->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertNull($server->package_id);
        $this->assertSame(3, $server->cpu_cores);
        $this->assertSame(3072, $server->memory_mib);
        $this->assertSame(30 * 1024, $server->disk_mib);
        $this->assertSame(200, $server->port_speed_mbps);
        $this->assertNull($server->traffic_bytes);
        $this->assertSame(ServerStatus::Active, $server->status);

        $vm = $this->proxmox->vms[$server->vmid];
        $this->assertEquals(3, $vm['config']['cores']);
        $this->assertStringContainsString('rate=25', $vm['config']['net0']);
        $this->assertStringContainsString('size=30720M', $vm['config']['scsi0']);

        $this->get(route('admin.servers.show', $server))
            ->assertInertia(fn (Assert $page) => $page->where('server.package', null));
    }

    public function test_custom_servers_may_use_network_models_no_package_allows()
    {
        $this->package->update(['network_models' => ['routed']]);

        $this->post(route('admin.servers.store'), $this->payload([
            'package' => null,
            'cpu_cores' => 1, 'memory_mib' => 1024, 'disk_gib' => 20, 'ipv4_count' => 1, 'ipv6_count' => 0,
        ]))->assertSessionHasNoErrors();
    }

    public function test_custom_resources_are_validated()
    {
        $this->post(route('admin.servers.store'), $this->payload([
            'package' => null,
            'cpu_cores' => 0,
            'memory_mib' => 64,
            'disk_gib' => null,
            'ipv4_count' => 0,
            'ipv6_count' => 0,
        ]))->assertSessionHasErrors(['cpu_cores', 'memory_mib', 'disk_gib']);

        $this->post(route('admin.servers.store'), $this->payload([
            'package' => null,
            'cpu_cores' => 1, 'memory_mib' => 1024, 'disk_gib' => 20, 'ipv4_count' => 0, 'ipv6_count' => 0,
        ]))->assertSessionHasErrors('ipv4_count');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_vms_can_be_adopted_without_a_package()
    {
        $this->proxmox->addVm(260, 'no-package-vm');

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 260,
            'package' => '',
            'network_model' => 'bridged',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Server::query()->sole()->package_id);
    }

    public function test_adopted_vms_can_be_given_to_a_billing_system()
    {
        $whmcs = BillingSystem::factory()->create();
        $this->proxmox->addVm(262, 'whmcs-vm');
        $this->proxmox->addVm(263, 'no-system-vm');

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 262,
            'billing_system' => $whmcs->uuid,
            'external_id' => '1001',
            'network_model' => 'bridged',
        ])->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertSame($whmcs->id, $server->billing_system_id);
        $this->assertSame('1001', $server->external_id);

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 263,
            'external_id' => '1002',
            'network_model' => 'bridged',
        ])->assertSessionHasErrors('billing_system');
    }

    public function test_adoption_reads_the_disk_size_from_the_config_when_cluster_resources_does_not_know_it()
    {
        $this->proxmox->addVm(261, 'stopped-vm');
        $this->proxmox->vms[261]['config']['scsi0'] = 'local-lvm:vm-261-disk-0,size=3G';
        $this->proxmox->unknownDiskSize[261] = true;

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 261,
            'package' => '',
            'network_model' => 'bridged',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3072, Server::query()->sole()->disk_mib);
    }

    public function test_the_password_is_only_shown_on_request()
    {
        $this->post(route('admin.servers.store'), $this->payload(['root_password' => 'chosen-password-123']));
        $server = Server::query()->sole();

        $this->post(route('admin.servers.password', $server))->assertSessionHas('revealed_password', 'chosen-password-123');
    }

    public function test_input_is_validated()
    {
        $this->post(route('admin.servers.store'), $this->payload([
            'hostname' => 'not_a_hostname!',
            'network_model' => 'nat',
            'root_password' => 'short',
            'ssh_keys' => 'not a key',
        ]))->assertSessionHasErrors(['hostname', 'network_model', 'root_password']);

        $this->post(route('admin.servers.store'), $this->payload(['ssh_keys' => 'not a key']))
            ->assertSessionHasErrors('ssh_keys');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_the_network_model_must_be_allowed_by_the_package()
    {
        $this->package->update(['network_models' => ['routed']]);

        $this->post(route('admin.servers.store'), $this->payload())->assertSessionHasErrors('network_model');
    }

    public function test_the_create_form_offers_the_apps_each_template_fits()
    {
        app(RecipeSynchronizer::class)->sync();
        $windows = OsTemplate::factory()->windows()->create();

        $this->get(route('admin.servers.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/servers/Create')
                ->where('apps', fn ($apps) => collect($apps)->firstWhere('slug', 'wireguard')['os_templates'] === [$this->osTemplate->uuid])
                ->where('apps', fn ($apps) => collect($apps)->every(fn (array $app) => ! in_array($windows->uuid, $app['os_templates'], true))));
    }

    public function test_a_server_can_be_created_with_an_app()
    {
        app(RecipeSynchronizer::class)->sync();

        $this->post(route('admin.servers.store'), $this->payload(['app' => 'openvpn', 'app_inputs' => ['port' => 1195]]))
            ->assertSessionHasNoErrors();

        $installation = Server::query()->sole()->appInstallations()->sole();
        $this->assertSame('openvpn', $installation->recipe->slug);
        $this->assertSame(AppInstallMode::Order, $installation->mode);
        $this->assertSame(AppInstallationStatus::Installing, $installation->status);
        $this->assertSame(['port' => 1195, 'protocol' => 'udp'], $installation->inputs);
    }

    public function test_apps_that_do_not_fit_and_invalid_app_inputs_are_shown_on_the_form()
    {
        app(RecipeSynchronizer::class)->sync();
        AppRecipe::query()->where('slug', 'docker')->update(['is_active' => false]);

        $this->post(route('admin.servers.store'), $this->payload(['app' => 'docker']))->assertSessionHasErrors('app');
        $this->post(route('admin.servers.store'), $this->payload(['app' => 'openvpn', 'app_inputs' => ['port' => 70000]]))->assertSessionHasErrors('app_inputs.port');
        $this->post(route('admin.servers.store'), $this->payload(['app' => 'no-such-app']))->assertSessionHasErrors('app');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_admins_can_grant_and_revoke_paid_apps()
    {
        app(RecipeSynchronizer::class)->sync();
        $openvpn = AppRecipe::query()->with('latestVersion')->where('slug', 'openvpn')->firstOrFail();
        $version = $openvpn->latestVersion;
        $this->assertNotNull($version);
        $version->forceFill(['definition' => ['billing' => 'paid'] + $version->definition])->save();
        $this->post(route('admin.servers.store'), $this->payload());
        $server = Server::query()->sole();

        $this->get(route('admin.servers.show', $server))->assertInertia(fn (Assert $page) => $page
            ->where('entitlementOptions', [['value' => 'openvpn', 'label' => 'OpenVPN']])
            ->has('entitlements', 0)
            ->where('appOptions', fn ($apps) => ! collect($apps)->contains('slug', 'openvpn')));

        $this->post(route('admin.servers.app-entitlements', $server), ['app' => 'openvpn', 'reference' => 'INV-7'])->assertSessionHasNoErrors();
        $this->post(route('admin.servers.app-entitlements', $server), ['app' => 'wireguard'])->assertSessionHasErrors('app');

        $entitlement = $server->appEntitlements()->sole();
        $this->assertSame(AppEntitlementSource::Admin, $entitlement->source);
        $this->assertSame($this->admin->id, $entitlement->user_id);

        $this->get(route('admin.servers.show', $server))->assertInertia(fn (Assert $page) => $page
            ->where('entitlementOptions', [])
            ->where('entitlements.0.reference', 'INV-7')
            ->where('entitlements.0.active', true)
            ->where('appOptions', fn ($apps) => collect($apps)->contains('slug', 'openvpn')));

        $this->delete(route('admin.app-entitlements.destroy', $entitlement))->assertRedirect();

        $this->assertNotNull($entitlement->refresh()->revoked_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'app.entitlement_revoked')->count());
    }

    public function test_provisioning_problems_are_shown_on_the_form()
    {
        IpAddress::query()->update(['is_reserved' => true]);

        $this->post(route('admin.servers.store'), $this->payload())->assertSessionHasErrors('server');
    }

    public function test_servers_can_be_suspended_unsuspended_and_deleted()
    {
        $this->post(route('admin.servers.store'), $this->payload());
        $server = Server::query()->sole();

        $this->post(route('admin.servers.suspend', $server));
        $this->assertSame(ServerStatus::Suspended, $server->refresh()->status);

        $this->post(route('admin.servers.unsuspend', $server));
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);

        $this->delete(route('admin.servers.destroy', $server));
        $this->assertSame(ServerStatus::Terminated, $server->refresh()->status);

        // Deleted servers are hidden unless asked for.
        $this->get(route('admin.servers.index'))->assertInertia(fn (Assert $page) => $page->has('servers.data', 0));
        $this->get(route('admin.servers.index', ['terminated' => 1]))->assertInertia(fn (Assert $page) => $page->has('servers.data', 1));
    }

    public function test_actions_are_refused_in_the_wrong_state()
    {
        $server = Server::factory()->for($this->node)->status(ServerStatus::Suspended)->create();

        $this->post(route('admin.servers.suspend', $server));

        $this->assertSame([], $this->proxmox->writes);
        $this->assertSame(0, $server->tasks()->count());
    }

    public function test_resources_in_use_cannot_be_deleted()
    {
        $this->post(route('admin.servers.store'), $this->payload());
        $address = IpAddress::query()->whereNotNull('server_id')->sole();

        $this->delete(route('admin.clusters.destroy', $this->cluster));
        $this->delete(route('admin.packages.destroy', $this->package));
        $this->delete(route('admin.ip-pools.destroy', $this->ipv4Pool));
        $this->delete(route('admin.ip-addresses.destroy', $address));

        $this->assertModelExists($this->cluster);
        $this->assertModelExists($this->package);
        $this->assertModelExists($this->ipv4Pool);
        $this->assertModelExists($address);
    }

    public function test_existing_vms_in_the_pool_can_be_adopted_without_touching_proxmox()
    {
        $this->proxmox->addVm(250, 'legacy-web', pool: 'proxmotion');
        $this->proxmox->addVm(251, 'not-in-pool', pool: null);
        $address = IpAddress::query()->where('address', '203.0.113.5')->sole();

        $this->get(route('admin.servers.adopt', ['cluster' => $this->cluster->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('candidates', 1)
                ->where('candidates.0.vmid', 250));

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 250,
            'package' => $this->package->uuid,
            'network_model' => 'bridged',
            'addresses' => [$address->id],
        ])->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertSame(ServerOrigin::Adopted, $server->origin);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('legacy-web', $server->hostname);
        $this->assertSame(2048, $server->memory_mib);
        $this->assertTrue($address->refresh()->server?->is($server));
        $this->assertSame([], $this->proxmox->writes, 'Adoption must not change anything on Proxmox.');

        // It is no longer offered for adoption.
        $this->get(route('admin.servers.adopt', ['cluster' => $this->cluster->uuid]))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 0));
    }

    public function test_vms_outside_the_pool_cannot_be_adopted()
    {
        $this->proxmox->addVm(251, 'not-in-pool', pool: null);

        $this->post(route('admin.servers.adopt.store'), [
            'cluster' => $this->cluster->uuid,
            'vmid' => 251,
            'package' => $this->package->uuid,
            'network_model' => 'bridged',
        ])->assertSessionHasErrors('vmid');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_permission_check_lists_missing_privileges()
    {
        $this->proxmox->permissions = [
            '/' => ['Sys.Audit', 'Datastore.Audit', 'VM.Audit'],
            '/pool/proxmotion' => ['VM.Audit', 'VM.Allocate', 'VM.Clone', 'VM.PowerMgmt', 'VM.Console', 'VM.Config.CPU', 'VM.Config.Memory', 'VM.Config.Disk', 'VM.Config.Network', 'VM.Config.Cloudinit', 'VM.Config.Options', 'VM.GuestAgent.Unrestricted'],
            '/storage' => ['Datastore.AllocateSpace'],
        ];

        $this->post(route('admin.clusters.permissions', $this->cluster));

        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck.0.missing', [])
                ->where('permissionCheck.1.missing', ['Pool.Allocate'])
                ->where('permissionCheck.3.path', '/sdn/zones/localnetwork')
                ->where('permissionCheck.3.missing', ['SDN.Use'])
                ->where('permissionCheck.4.path', '/vms')
                ->where('permissionCheck.4.excess', [])
                // The template isn't clonable (e.g. not in the pool).
                ->where('permissionCheck.5.path', '/vms/9000')
                ->where('permissionCheck.5.missing', ['VM.Clone']));
    }

    public function test_permission_check_asks_for_the_guest_agent_privilege_of_the_proxmox_version()
    {
        $this->post(route('admin.clusters.permissions', $this->cluster));
        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck.1.missing', fn ($missing) => in_array('VM.GuestAgent.Unrestricted', [...$missing], true)));

        $this->cluster->forceFill(['pve_version' => '8.4.1'])->save();

        $this->post(route('admin.clusters.permissions', $this->cluster));
        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck.1.missing', fn ($missing) => in_array('VM.Monitor', [...$missing], true)
                    && ! in_array('VM.GuestAgent.Unrestricted', [...$missing], true)));
    }

    public function test_permission_check_ignores_templates_the_panel_does_not_use()
    {
        // A template on the cluster that no OS template is mapped to (e.g. outside the pool).
        ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->id,
            'vmid' => 9100,
            'name' => 'someone-elses-template',
        ]);

        $this->post(route('admin.clusters.permissions', $this->cluster));

        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck', fn ($checks) => collect($checks)->pluck('path')->contains('/vms/9000')
                    && ! collect($checks)->pluck('path')->contains('/vms/9100')));
    }

    public function test_permission_check_flags_root_equivalent_tokens()
    {
        $everything = ['Sys.Audit', 'Datastore.Audit', 'VM.Audit', 'Permissions.Modify', 'Sys.Modify', 'VM.Allocate', 'VM.PowerMgmt', 'VM.Config.Disk'];
        $this->proxmox->permissions = ['/' => $everything, '/vms' => $everything];

        $this->post(route('admin.clusters.permissions', $this->cluster));

        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck.0.excess', ['Permissions.Modify', 'Sys.Modify'])
                ->where('permissionCheck.4.excess', ['VM.Allocate', 'VM.PowerMgmt', 'VM.Config.Disk']));
    }

    public function test_privileges_reported_without_propagation_still_count()
    {
        // Proxmox reports a VM's pool-inherited privileges with propagate = 0 (seen on PVE 9.2.2).
        $this->proxmox->unpropagatedPermissions = [
            '/vms/9000' => ['VM.Clone'],
            '/' => ['Sys.Modify'],
        ];

        $this->post(route('admin.clusters.permissions', $this->cluster));

        $this->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissionCheck.0.path', '/')
                ->where('permissionCheck.0.excess', ['Sys.Modify'])
                ->where('permissionCheck.5.path', '/vms/9000')
                ->where('permissionCheck.5.missing', []));
    }

    public function test_admins_can_create_administrators()
    {
        $this->post(route('admin.users.store'), [
            'name' => 'Ayşe Yılmaz',
            'email' => 'ayse@example.com',
            'password' => 'a-strong-password',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::query()->where('email', 'ayse@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication(), 'They set up 2FA themselves before any admin page opens.');
        $this->assertSame('user.created', AuditLog::query()->sole()->action);
    }

    public function test_entered_ssh_keys_are_added_once()
    {
        $this->post(route('admin.servers.store'), $this->payload(['ssh_keys' => PublicKeyTest::RSA."\n".PublicKeyTest::ED25519."\n".PublicKeyTest::RSA]));

        $this->assertSame(implode("\n", [PublicKeyTest::RSA, PublicKeyTest::ED25519]), Server::query()->sole()->ssh_keys);
    }

    public function test_billing_ids_are_stored_and_service_ids_are_unique_per_billing_system()
    {
        $whmcs = BillingSystem::factory()->create();
        $wisecp = BillingSystem::factory()->create();

        $this->post(route('admin.servers.store'), $this->payload(['billing_system' => $whmcs->uuid, 'external_id' => ' 1001 ', 'external_client_id' => '42']))
            ->assertSessionHasNoErrors();

        $server = Server::query()->sole();
        $this->assertSame($whmcs->id, $server->billing_system_id);
        $this->assertSame('1001', $server->external_id);
        $this->assertSame('42', $server->external_client_id);

        $this->post(route('admin.servers.store'), $this->payload(['hostname' => 'web2.example.com', 'billing_system' => $whmcs->uuid, 'external_id' => '1001']))
            ->assertSessionHasErrors('external_id');

        // The same service id in another billing system is a different service.
        $this->post(route('admin.servers.store'), $this->payload(['hostname' => 'web3.example.com', 'billing_system' => $wisecp->uuid, 'external_id' => '1001']))
            ->assertSessionHasNoErrors();
    }

    public function test_billing_ids_need_a_billing_system()
    {
        $this->post(route('admin.servers.store'), $this->payload(['external_id' => '1001']))
            ->assertSessionHasErrors('billing_system');

        $this->post(route('admin.servers.store'), $this->payload(['billing_system' => 'unknown']))
            ->assertSessionHasErrors('billing_system');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_weak_or_obsolete_ssh_keys_are_refused()
    {
        $dss = 'ssh-dss '.base64_encode(pack('N', 7).'ssh-dss'.pack('N', 1).'x');

        $this->post(route('admin.servers.store'), $this->payload(['ssh_keys' => $dss]))
            ->assertSessionHasErrors('ssh_keys');
    }

    public function test_retrying_a_failed_delete_deletes_again()
    {
        $server = Server::factory()->for($this->node)->status(ServerStatus::Error)->create(['vmid' => 4242]);
        $failed = new Task;
        $failed->forceFill(['server_id' => $server->id, 'type' => TaskType::Terminate, 'status' => TaskStatus::Failed, 'steps' => []])->save();

        $this->post(route('admin.servers.retry', $server));

        $this->assertSame(TaskType::Terminate, Task::query()->latest('id')->firstOrFail()->type);
        $this->assertSame(ServerStatus::Terminated, $server->refresh()->status);
    }

    public function test_cluster_resource_pool_is_validated()
    {
        $this->put(route('admin.clusters.update', Cluster::query()->sole()), [
            'location' => $this->location->uuid,
            'name' => 'x',
            'hosts' => ['pve1.example.com'],
            'port' => 8006,
            'token_id' => 'proxmotion@pve!panel',
            'verify_tls' => true,
            'resource_pool' => 'not a pool!',
            'is_active' => true,
        ])->assertSessionHasErrors('resource_pool');
    }
}
