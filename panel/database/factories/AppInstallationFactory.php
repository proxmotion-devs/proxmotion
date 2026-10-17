<?php

namespace Database\Factories;

use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppInstallation>
 */
class AppInstallationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'app_recipe_id' => AppRecipe::factory(),
            // The app's latest version, unless given.
            'app_recipe_version_id' => fn (array $attributes) => AppRecipe::query()->whereKey($attributes['app_recipe_id'])->value('latest_version_id'),
            'status' => AppInstallationStatus::Installed,
            'inputs' => ['port' => 8443],
            'progress' => 100,
            'step' => 'Installed',
            'attempts' => 1,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ];
    }
}
