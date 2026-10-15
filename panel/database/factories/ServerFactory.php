<?php

namespace Database\Factories;

use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Models\Node;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'package_id' => Package::factory(),
            'node_id' => Node::factory(),
            'cluster_id' => fn (array $attributes) => Node::query()->whereKey($attributes['node_id'])->firstOrFail()->cluster_id,
            'location_id' => fn (array $attributes) => Node::query()->whereKey($attributes['node_id'])->firstOrFail()->cluster->location_id,
            'vmid' => fake()->unique()->numberBetween(1000, 99999),
            'hostname' => fake()->unique()->domainWord().'.example.com',
            'status' => ServerStatus::Active,
            'origin' => ServerOrigin::Provisioned,
            'network_model' => NetworkModel::Bridged,
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_mib' => 40960,
        ];
    }

    public function status(ServerStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
