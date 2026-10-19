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
     * The family and platform follow from the distribution (docs/adr/0024).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Ubuntu 24.04',
            'distribution' => 'ubuntu',
            'version' => '24.04',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function windows(): static
    {
        return $this->state(['name' => 'Windows Server 2022', 'distribution' => 'windows', 'version' => '2022']);
    }
}
