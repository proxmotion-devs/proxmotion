<?php

namespace Database\Factories;

use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A small recipe for Ubuntu 24.04 (the OsTemplate factory's) with a port and
 * a password input, a text output and a secret file output, created with one
 * approved version 1.0.0 (format 1).
 *
 * @extends Factory<AppRecipe>
 */
class AppRecipeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'demo-'.$this->faker->unique()->numberBetween(100, 99999),
            'source' => AppRecipe::SOURCE_BUNDLED,
            'name' => 'Demo app',
            'category' => 'vpn',
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (AppRecipe $app) {
            $version = (new AppRecipeVersion)->forceFill([
                'app_recipe_id' => $app->id,
                'version' => '1.0.0',
                'format' => 1,
                'definition' => self::recipeDefinition($app->slug),
                'files' => ['install.sh' => "#!/bin/bash\nset -euo pipefail\necho demo\n"],
                'checksum' => str_repeat('0', 64),
                'status' => AppRecipeVersionStatus::Approved,
            ]);
            $version->save();

            $app->forceFill(['latest_version_id' => $version->id])->save();
            $app->setRelation('latestVersion', $version);
        });
    }

    /**
     * The recipe.yaml of the factory's version 1.0.0 (format 1).
     *
     * @return array<string, mixed>
     */
    public static function recipeDefinition(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => 'Demo app',
            'version' => '1.0.0',
            'category' => 'vpn',
            'description' => ['en' => 'A demo app.', 'tr' => 'Bir deneme uygulaması.'],
            'os' => [['family' => 'ubuntu', 'versions' => ['22.04', '24.04']]],
            'requirements' => ['min_memory_mib' => 512, 'min_disk_mib' => 5120, 'min_cores' => 1],
            'network_models' => ['bridged', 'routed'],
            'install_modes' => ['order', 'post'],
            'billing' => 'free',
            'license' => 'none',
            'inputs' => [
                ['key' => 'port', 'type' => 'port', 'default' => 8443, 'label' => ['en' => 'Port']],
                ['key' => 'admin_password', 'type' => 'password', 'required' => false, 'label' => ['en' => 'Admin password']],
            ],
            'outputs' => [
                ['key' => 'url', 'type' => 'url', 'label' => ['en' => 'Address']],
                ['key' => 'client_config', 'type' => 'file', 'filename' => 'client.conf', 'secret' => true],
            ],
            'timeout_minutes' => 15,
        ];
    }

    /**
     * Change entries of the recipe definition of the created version (e.g.
     * requirements, billing). A format 2 definition replaces it as a whole
     * when $changes has `format: 2`.
     *
     * @param  array<string, mixed>  $changes
     */
    public function withDefinition(array $changes): static
    {
        return $this->afterCreating(function (AppRecipe $app) use ($changes) {
            $version = $app->latestVersion ?? $app->current();
            $definition = ($changes['format'] ?? 1) === 2 ? $changes : array_replace($version->definition, $changes);
            $version->forceFill([
                'definition' => $definition,
                'format' => (int) ($definition['format'] ?? 1),
                'version' => (string) ($definition['version'] ?? $version->version),
            ])->save();
            $app->setRelation('latestVersion', $version->refresh());
        });
    }
}
