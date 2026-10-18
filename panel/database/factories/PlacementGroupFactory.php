<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\PlacementGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlacementGroup>
 */
class PlacementGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
        ];
    }
}
