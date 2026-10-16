<?php

namespace Database\Factories;

use App\Models\BillingSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingSystem>
 */
class BillingSystemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }
}
