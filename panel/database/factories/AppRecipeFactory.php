<?php

namespace Database\Factories;

use App\Models\AppRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A small recipe for Ubuntu 24.04 (the OsTemplate factory's) with a port and
 * a password input, a text output and a secret file output.
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
        $slug = 'demo-'.$this->faker->unique()->numberBetween(100, 99999);

        return [
            'slug' => $slug,
            'source' => AppRecipe::SOURCE_BUNDLED,
            'name' => 'Demo app',
            'version' => '1.0.0',
            'category' => 'vpn',
            'definition' => [
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
            ],
            'files' => ['install.sh' => "#!/bin/bash\nset -euo pipefail\necho demo\n"],
            'checksum' => str_repeat('0', 64),
            'is_active' => true,
        ];
    }

    /**
     * Change entries of the recipe definition (e.g. requirements, billing).
     *
     * @param  array<string, mixed>  $changes
     */
    public function withDefinition(array $changes): static
    {
        return $this->state(fn (array $attributes) => ['definition' => array_replace($attributes['definition'], $changes)]);
    }
}
