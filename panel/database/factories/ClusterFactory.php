<?php

namespace Database\Factories;

use App\Models\Cluster;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cluster>
 */
class ClusterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'name' => 'Cluster '.fake()->unique()->numberBetween(1, 9999),
            'hosts' => ['pve1.example.com'],
            'port' => 8006,
            'token_id' => 'proxmotion@pve!panel',
            'token_secret' => fake()->uuid(),
            'verify_tls' => true,
            'is_active' => true,
        ];
    }
}
