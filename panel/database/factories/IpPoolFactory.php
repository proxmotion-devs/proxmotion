<?php

namespace Database\Factories;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpPool;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpPool>
 */
class IpPoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'name' => 'Pool '.fake()->unique()->numberBetween(1, 9999),
            'version' => IpVersion::V4,
            'network_model' => NetworkModel::Bridged,
            'network' => '203.0.113.0/28',
            'gateway' => '203.0.113.1',
            'bridge' => 'vmbr0',
            'vlan_tag' => null,
            'nameservers' => ['192.0.2.53'],
            'is_active' => true,
        ];
    }

    public function ipv6(): static
    {
        return $this->state(fn (array $attributes) => [
            'version' => IpVersion::V6,
            'network' => '2001:db8:100::/48',
            'gateway' => '2001:db8:100::1',
            'nameservers' => ['2001:db8::53'],
        ]);
    }
}
