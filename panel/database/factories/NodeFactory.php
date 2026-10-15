<?php

namespace Database\Factories;

use App\Enums\NodeStatus;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cluster_id' => Cluster::factory(),
            'name' => fake()->unique()->lexify('pve-????'),
            'status' => NodeStatus::Online,
            'is_maintenance' => false,
            'cpu_cores' => 32,
            'memory_bytes' => 128 * 1024 ** 3,
            'last_seen_at' => now(),
        ];
    }
}
