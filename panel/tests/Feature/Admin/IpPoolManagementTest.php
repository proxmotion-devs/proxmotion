<?php

namespace Tests\Feature\Admin;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\Server;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesProxmox;

class IpPoolManagementTest extends AdminTestCase
{
    use FakesProxmox;

    /**
     * @return array<string, mixed>
     */
    private function payload(Location $location, array $overrides = []): array
    {
        return [
            'location' => $location->uuid,
            'name' => 'fra1-v4',
            'version' => 4,
            'network_model' => 'bridged',
            'network' => '203.0.113.0/28',
            'gateway' => '203.0.113.1',
            'bridge' => 'vmbr0',
            'vlan_tag' => null,
            'nameservers' => ['192.0.2.53'],
            'is_active' => true,
            'add_mode' => 'network',
            'addresses' => '',
            ...$overrides,
        ];
    }

    /**
     * A node in the location whose bridge has 203.0.113.11 configured.
     */
    private function nodeWithAddress(Location $location, string $cidr = '203.0.113.11/24'): Node
    {
        $node = Node::factory()->for(Cluster::factory()->for($location))->create();
        $node->bridges()->forceCreate(['name' => 'vmbr0', 'is_active' => true, 'cidr' => $cidr]);

        return $node;
    }

    public function test_the_whole_network_can_be_added_when_it_belongs_to_you()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location))->assertSessionHasNoErrors();

        $pool = IpPool::query()->sole();
        $this->assertSame(IpVersion::V4, $pool->version);
        $this->assertSame(NetworkModel::Bridged, $pool->network_model);
        $this->assertSame(13, $pool->addresses()->count()); // 14 usable hosts minus the gateway
    }

    public function test_only_listed_addresses_are_added_by_default()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'network' => '203.0.113.0/24',
            'add_mode' => 'list',
            'addresses' => "203.0.113.140, 203.0.113.141\n203.0.113.150-152 203.0.113.200/30",
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            ['203.0.113.140', '203.0.113.141', '203.0.113.150', '203.0.113.151', '203.0.113.152', '203.0.113.200', '203.0.113.201', '203.0.113.202', '203.0.113.203'],
            IpPool::query()->sole()->addresses()->orderBy('id')->pluck('address')->all(),
        );
    }

    public function test_a_pool_can_be_created_without_addresses()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['add_mode' => 'none']))->assertSessionHasNoErrors();

        $this->assertSame(0, IpPool::query()->sole()->addresses()->count());
    }

    public function test_invalid_or_outside_addresses_reject_the_whole_form()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'add_mode' => 'list',
            'addresses' => '203.0.113.5, 198.51.100.7, not-an-ip',
        ]))->assertSessionHasErrors('addresses');

        $this->assertDatabaseCount('ip_pools', 0);
    }

    public function test_node_ips_gateway_and_broadcast_are_never_added()
    {
        $location = Location::factory()->create();
        $this->nodeWithAddress($location);

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'network' => '203.0.113.0/24',
            'add_mode' => 'list',
            'addresses' => '203.0.113.0-203.0.113.12, 203.0.113.255',
        ]))->assertSessionHasNoErrors();

        $addresses = IpPool::query()->sole()->addresses()->pluck('address')->all();
        $this->assertNotContains('203.0.113.0', $addresses);
        $this->assertNotContains('203.0.113.1', $addresses, 'gateway');
        $this->assertNotContains('203.0.113.11', $addresses, 'node IP');
        $this->assertNotContains('203.0.113.255', $addresses, 'broadcast');
        $this->assertCount(10, $addresses);
    }

    public function test_sync_reserves_node_ips_already_in_a_pool()
    {
        $location = Location::factory()->create();
        $pool = IpPool::factory()->for($location)->create(['network' => '192.0.2.0/24', 'gateway' => '192.0.2.1']);
        $address = $pool->addresses()->create(['address' => '192.0.2.11']);
        $cluster = Cluster::factory()->for($location)->create();
        $this->fakeProxmox(); // the fixture's vmbr0 has 192.0.2.11/24

        app(ClusterSynchronizer::class)->sync($cluster);

        $address->refresh();
        $this->assertTrue($address->is_reserved);
        $this->assertSame('Proxmox node', $address->notes);
    }

    public function test_networks_are_normalised()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['network' => '203.0.113.5/28']))
            ->assertSessionHasNoErrors();

        $this->assertSame('203.0.113.0/28', IpPool::query()->sole()->network);
    }

    public function test_whole_networks_cannot_be_added_for_ipv6()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'version' => 6,
            'network' => '2001:db8:100::/48',
            'gateway' => '2001:db8:100::1',
            'nameservers' => ['2001:db8::53'],
        ]))->assertSessionHasErrors('add_mode');

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'version' => 6,
            'network' => '2001:db8:100::/48',
            'gateway' => '2001:db8:100::1',
            'nameservers' => ['2001:db8::53'],
            'add_mode' => 'none',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, IpPool::query()->sole()->addresses()->count());
    }

    public function test_network_and_gateway_are_validated()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['network' => '203.0.113.0']))
            ->assertSessionHasErrors('network');
        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['network' => '2001:db8::/48']))
            ->assertSessionHasErrors('network');
        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['gateway' => '198.51.100.1']))
            ->assertSessionHasErrors('gateway');

        $this->assertDatabaseCount('ip_pools', 0);
    }

    public function test_routed_pools_may_use_a_gateway_outside_the_block()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'network_model' => 'routed',
            'network' => '203.0.113.64/29',
            'gateway' => '198.51.100.254',
        ]))->assertSessionHasNoErrors();
    }

    public function test_oversized_networks_are_rejected()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['network' => '198.18.0.0/15', 'gateway' => null]))
            ->assertSessionHasErrors('network');

        $this->assertDatabaseCount('ip_pools', 0);
    }

    public function test_network_cannot_be_changed_after_creation()
    {
        $pool = IpPool::factory()->create();

        $this->put(route('admin.ip-pools.update', $pool), [
            'name' => 'renamed',
            'network' => '198.51.100.0/24',
            'network_model' => 'bridged',
            'gateway' => '203.0.113.1',
            'bridge' => 'vmbr1',
            'nameservers' => [],
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $pool->refresh();
        $this->assertSame('renamed', $pool->name);
        $this->assertSame('203.0.113.0/28', $pool->network);
        $this->assertSame('vmbr1', $pool->bridge);
        $this->assertFalse($pool->is_active);
    }

    public function test_pools_isolate_their_servers_only_when_turned_on()
    {
        // docs/adr/0034
        $pool = IpPool::factory()->create();
        $this->assertFalse($pool->refresh()->isolate_guests, 'Off by default.');

        $this->put(route('admin.ip-pools.update', $pool), [
            'name' => $pool->name,
            'network_model' => 'bridged',
            'gateway' => $pool->gateway,
            'bridge' => 'vmbr0',
            'nameservers' => [],
            'is_active' => true,
            'isolate_guests' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($pool->refresh()->isolate_guests);
    }

    public function test_single_ips_lists_and_ranges_can_be_added_inside_the_network_only()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24']);

        $this->post(route('admin.ip-addresses.store', $pool), ['addresses' => '203.0.113.50'])->assertSessionHasNoErrors();
        $this->post(route('admin.ip-addresses.store', $pool), ['addresses' => '203.0.113.10-12, 203.0.113.50'])->assertSessionHasNoErrors();
        $this->post(route('admin.ip-addresses.store', $pool), ['addresses' => '198.51.100.1'])->assertSessionHasErrors('addresses');
        $this->post(route('admin.ip-addresses.store', $pool), ['addresses' => '203.0.113.20-203.0.113.10'])->assertSessionHasErrors('addresses');

        $this->assertSame(4, $pool->addresses()->count());
    }

    public function test_single_ipv6_addresses_can_be_added()
    {
        $pool = IpPool::factory()->ipv6()->create();

        $this->post(route('admin.ip-addresses.store', $pool), ['addresses' => '2001:DB8:100:0::10'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2001:db8:100::10', $pool->addresses()->sole()->address);
    }

    public function test_addresses_can_be_reserved_and_get_a_mac()
    {
        $pool = IpPool::factory()->create();
        $address = $pool->addresses()->create(['address' => '203.0.113.5']);

        $this->patch(route('admin.ip-addresses.update', $address), [
            'is_reserved' => true,
            'mac_address' => '02-00-00-AA-BB-CC',
            'notes' => 'router',
        ])->assertSessionHasNoErrors();

        $address = IpAddress::query()->findOrFail($address->id);
        $this->assertTrue($address->is_reserved);
        $this->assertSame('02:00:00:aa:bb:cc', $address->mac_address);
    }

    public function test_bulk_actions_never_delete_assigned_addresses()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24']);
        $free = $pool->addresses()->create(['address' => '203.0.113.10']);
        $assigned = $pool->addresses()->create(['address' => '203.0.113.11']);
        $server = Server::factory()->create();
        $assigned->forceFill(['server_id' => $server->id])->save();
        $otherPool = IpPool::factory()->create(['network' => '198.51.100.0/24']);
        $foreign = $otherPool->addresses()->create(['address' => '198.51.100.10']);

        $this->post(route('admin.ip-addresses.bulk', $pool), ['action' => 'reserve', 'ids' => [$free->id, $foreign->id]]);
        $this->assertTrue($free->refresh()->is_reserved);
        $this->assertFalse($foreign->refresh()->is_reserved, 'Addresses of other pools are ignored.');

        $this->post(route('admin.ip-addresses.bulk', $pool), ['action' => 'delete', 'ids' => [$free->id, $assigned->id]]);
        $this->assertModelMissing($free);
        $this->assertModelExists($assigned);
    }

    public function test_keep_only_deletes_every_other_unassigned_address()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24', 'gateway' => '203.0.113.1']);
        $pool->addresses()->createMany(array_map(fn ($i) => ['address' => "203.0.113.{$i}"], range(2, 20)));
        $assigned = $pool->addresses()->where('address', '203.0.113.20')->sole();
        $assigned->forceFill(['server_id' => Server::factory()->create()->id])->save();

        $this->post(route('admin.ip-addresses.keep-only', $pool), ['addresses' => '203.0.113.5, 203.0.113.6'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['203.0.113.5', '203.0.113.6', '203.0.113.20'],
            $pool->addresses()->orderBy('id')->pluck('address')->all(),
        );
    }

    public function test_pools_with_assigned_addresses_cannot_be_deleted_and_say_why()
    {
        $pool = IpPool::factory()->create();
        $address = $pool->addresses()->create(['address' => '203.0.113.5']);
        $address->forceFill(['server_id' => Server::factory()->create()->id])->save();

        $this->delete(route('admin.ip-pools.destroy', $pool))
            ->assertSessionHas('inertia.flash_data.toast.type', 'error');

        $this->assertModelExists($pool);
    }

    public function test_unused_pools_can_be_deleted_from_the_list()
    {
        $pool = IpPool::factory()->create();
        $pool->addresses()->create(['address' => '203.0.113.5']);

        $this->delete(route('admin.ip-pools.destroy', $pool))->assertRedirect(route('admin.ip-pools.index'));

        $this->assertModelMissing($pool);
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_pool_page_shows_counts_search_and_protected_addresses()
    {
        $location = Location::factory()->create();
        $this->nodeWithAddress($location);
        $pool = IpPool::factory()->for($location)->create(['network' => '203.0.113.0/24', 'gateway' => '203.0.113.1']);
        $pool->addresses()->createMany([['address' => '203.0.113.50'], ['address' => '203.0.113.60', 'is_reserved' => true]]);

        $this->get(route('admin.ip-pools.show', ['ipPool' => $pool, 'search' => '.50']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.total', 2)
                ->where('counts.reserved', 1)
                ->has('addresses.data', 1)
                ->where('addresses.data.0.address', '203.0.113.50')
                ->where('protected', [
                    '203.0.113.1' => 'Gateway',
                    '203.0.113.0' => 'Network address',
                    '203.0.113.255' => 'Broadcast address',
                    '203.0.113.11' => 'Proxmox node',
                ]));
    }

    public function test_the_pool_page_shows_the_mac_address_of_the_assigned_server()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24']);
        $address = $pool->addresses()->create(['address' => '203.0.113.5']);
        $server = Server::factory()->create();
        $server->forceFill(['mac_address' => 'bc:24:11:aa:bb:cc'])->save();
        $address->forceFill(['server_id' => $server->id])->save();

        $this->get(route('admin.ip-pools.show', $pool))
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.data.0.mac_address', null)
                ->where('addresses.data.0.server.mac_address', 'bc:24:11:aa:bb:cc'));
    }
}
