<?php

namespace Database\Factories;

use App\Models\OsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OsTemplate>
 */
class OsTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ubuntu 24.04',
            'family' => 'ubuntu',
            'version' => '24.04',
            'platform' => 'linux',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function windows(): static
    {
        return $this->state(['name' => 'Windows Server 2022', 'family' => 'windows', 'version' => '2022', 'platform' => 'windows']);
    }
}
