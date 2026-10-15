<?php

namespace Database\Factories;

use App\Enums\NetworkModel;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'VPS '.fake()->unique()->numberBetween(1, 9999),
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_mib' => 40960,
            'traffic_bytes' => 2 * 1000 ** 4,
            'port_speed_mbps' => 1000,
            'ipv4_count' => 1,
            'ipv6_count' => 1,
            'snapshot_slots' => 1,
            'backup_slots' => 1,
            'network_models' => [NetworkModel::Bridged, NetworkModel::Routed],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
