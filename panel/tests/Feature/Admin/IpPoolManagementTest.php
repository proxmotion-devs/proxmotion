<?php

namespace Tests\Feature\Admin;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;

class IpPoolManagementTest extends AdminTestCase
{
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
            ...$overrides,
        ];
    }

    public function test_creating_an_ipv4_pool_adds_its_addresses()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location))->assertSessionHasNoErrors();

        $pool = IpPool::query()->sole();
        $this->assertSame(IpVersion::V4, $pool->version);
        $this->assertSame(NetworkModel::Bridged, $pool->network_model);
        $this->assertSame(13, $pool->addresses()->count()); // 14 usable hosts minus the gateway
    }

    public function test_networks_are_normalised()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, ['network' => '203.0.113.5/28']))
            ->assertSessionHasNoErrors();

        $this->assertSame('203.0.113.0/28', IpPool::query()->sole()->network);
    }

    public function test_ipv6_pools_do_not_get_pregenerated_addresses()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.ip-pools.store'), $this->payload($location, [
            'version' => 6,
            'network' => '2001:db8:100::/48',
            'gateway' => '2001:db8:100::1',
            'nameservers' => ['2001:db8::53'],
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

    public function test_address_ranges_can_be_added_inside_the_network_only()
    {
        $pool = IpPool::factory()->create(['network' => '203.0.113.0/24']);

        $this->post(route('admin.ip-addresses.store', $pool), ['from' => '203.0.113.10', 'to' => '203.0.113.12'])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.ip-addresses.store', $pool), ['from' => '203.0.113.10', 'to' => '198.51.100.1'])
            ->assertSessionHasErrors('to');
        $this->post(route('admin.ip-addresses.store', $pool), ['from' => '203.0.113.20', 'to' => '203.0.113.10'])
            ->assertSessionHasErrors('to');

        $this->assertSame(3, $pool->addresses()->count());
    }

    public function test_single_ipv6_addresses_can_be_added()
    {
        $pool = IpPool::factory()->ipv6()->create();

        $this->post(route('admin.ip-addresses.store', $pool), ['address' => '2001:DB8:100:0::10'])
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
}
