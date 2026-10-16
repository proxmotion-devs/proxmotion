<?php

namespace Tests\Feature\Admin;

use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Cluster;
use App\Models\IpAddress;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'user' => $this->customer->uuid,
            'location' => $this->location->uuid,
            'resource_mode' => 'package',
            'package' => $this->package->uuid,
            'os_template' => $this->osTemplate->uuid,
            'network_model' => 'bridged',
            'hostname' => 'Web1.Example.com',
            'root_password' => '',
            'ssh_keys' => '',
            ...$overrides,
        ];
    }

    public function test_an_admin_can_create_a_server_for_a_customer()
    {
        $response = $this->post(route('admin.servers.store'), $this->payload());

        $server = Server::query()->sole();
        $response->assertRedirect(route('admin.servers.show', $server));
        $this->assertSame('web1.example.com', $server->hostname);
        $this->assertTrue($server->user->is($this->customer));
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

    public function test_a_server_can_be_created_with_custom_resources_and_no_package()
    {
        $this->post(route('admin.servers.store'), $this->payload([
            'resource_mode' => 'custom',
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
            'resource_mode' => 'custom',
            'cpu_cores' => 1, 'memory_mib' => 1024, 'disk_gib' => 20, 'ipv4_count' => 1, 'ipv6_count' => 0,
        ]))->assertSessionHasNoErrors();
    }

    public function test_custom_resources_are_validated()
    {
        $this->post(route('admin.servers.store'), $this->payload([
            'resource_mode' => 'custom',
            'cpu_cores' => 0,
            'memory_mib' => 64,
            'disk_gib' => null,
            'ipv4_count' => 0,
            'ipv6_count' => 0,
        ]))->assertSessionHasErrors(['cpu_cores', 'memory_mib', 'disk_gib']);

        $this->post(route('admin.servers.store'), $this->payload([
            'resource_mode' => 'custom',
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
            'user' => $this->customer->uuid,
            'package' => '',
            'network_model' => 'bridged',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Server::query()->sole()->package_id);
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
            'user' => $this->customer->uuid,
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
            'user' => $this->customer->uuid,
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

    public function test_admins_can_create_customers()
    {
        $this->post(route('admin.users.store'), [
            'name' => 'Ayşe Yılmaz',
            'email' => 'ayse@example.com',
            'password' => 'a-strong-password',
            'role' => 'admin',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::query()->where('email', 'ayse@example.com')->sole();
        $this->assertFalse($user->isAdmin(), 'The role cannot be chosen through the form.');
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_customers_saved_ssh_keys_are_added_once()
    {
        $this->actingAs($this->customer)->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => PublicKeyTest::ED25519]);
        $this->actingAs($this->customer)->post(route('ssh-keys.store'), ['name' => 'Desktop', 'public_key' => PublicKeyTest::ECDSA]);
        $this->actingAs($this->admin);

        // The ed25519 key is entered too: it must appear only once.
        $this->post(route('admin.servers.store'), $this->payload(['ssh_keys' => PublicKeyTest::RSA."\n".PublicKeyTest::ED25519]));

        $this->assertSame(
            implode("\n", [PublicKeyTest::RSA, PublicKeyTest::ED25519, PublicKeyTest::ECDSA]),
            Server::query()->sole()->ssh_keys,
        );
    }

    public function test_saved_ssh_keys_can_be_left_out()
    {
        $this->actingAs($this->customer)->post(route('ssh-keys.store'), ['name' => 'Laptop', 'public_key' => PublicKeyTest::ED25519]);
        $this->actingAs($this->admin);

        $this->post(route('admin.servers.store'), $this->payload(['use_saved_keys' => false]));

        $this->assertNull(Server::query()->sole()->ssh_keys);
    }

    public function test_weak_or_obsolete_ssh_keys_are_refused()
    {
        $dss = 'ssh-dss '.base64_encode(pack('N', 7).'ssh-dss'.pack('N', 1).'x');

        $this->post(route('admin.servers.store'), $this->payload(['ssh_keys' => $dss]))
            ->assertSessionHasErrors('ssh_keys');
    }

    public function test_retrying_a_failed_delete_deletes_again()
    {
        $server = Server::factory()->for($this->node)->for($this->customer)->status(ServerStatus::Error)->create(['vmid' => 4242]);
        $failed = new Task;
        $failed->forceFill(['server_id' => $server->id, 'type' => TaskType::Terminate, 'status' => TaskStatus::Failed, 'steps' => []])->save();

        $this->post(route('admin.servers.retry', $server));

        $this->assertSame(TaskType::Terminate, Task::query()->latest('id')->firstOrFail()->type);
        $this->assertSame(ServerStatus::Terminated, $server->refresh()->status);
    }

    public function test_customers_only_see_their_own_servers()
    {
        Server::factory()->for($this->node)->for($this->customer)->create(['hostname' => 'mine.example.com']);
        Server::factory()->for($this->node)->create(['hostname' => 'theirs.example.com']);

        $this->actingAs($this->customer)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Dashboard')
                ->has('servers.data', 1)
                ->where('servers.data.0.hostname', 'mine.example.com'))
            ->assertDontSee('theirs.example.com');
    }

    public function test_customers_cannot_reach_server_management()
    {
        $server = Server::factory()->for($this->node)->for($this->customer)->create();
        $this->actingAs($this->customer);

        $this->get(route('admin.servers.show', $server))->assertForbidden();
        $this->post(route('admin.servers.suspend', $server))->assertForbidden();
        $this->delete(route('admin.servers.destroy', $server))->assertForbidden();
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
