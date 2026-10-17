<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\RecipeLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:make {slug : lower-case letters, digits and dashes, e.g. uptime-kuma} {--name= : display name}')]
#[Description('Create a format 2 app recipe skeleton in panel/recipes (docs/adr/0016)')]
class MakeApp extends Command
{
    public function handle(RecipeLoader $loader): int
    {
        $slug = $this->argument('slug');
        $slug = is_string($slug) ? $slug : '';

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug) !== 1) {
            $this->components->error('The slug must have 3 to 50 lower-case letters, digits and dashes, and start and end with a letter or digit.');

            return self::FAILURE;
        }

        $directory = $loader->directory().'/'.$slug;

        if (file_exists($directory)) {
            $this->components->error("{$directory} already exists.");

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : ucwords(str_replace('-', ' ', $slug));

        mkdir($directory, 0755, true);

        foreach (self::files($slug, $name) as $file => $content) {
            file_put_contents($directory.'/'.$file, $content);
        }

        $this->components->info("Created {$directory}.");
        $this->components->bulletList([
            'Edit recipe.yaml (requirements, inputs, outputs) and the scripts.',
            'php artisan apps:validate',
            'shellcheck recipes/'.$slug.'/*.sh',
            'php artisan apps:sync',
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private static function files(string $slug, string $name): array
    {
        $yaml = <<<YAML
            format: 2
            slug: {$slug}
            name: {$name}
            version: 0.1.0
            category: web
            description:
                en: What the app is and what the customer gets.
            maintainers: [{ name: Your name }]
            requires:
                platform: linux
                capabilities: [systemd, apt|dnf, bash]
            resources: { min_memory_mib: 512, min_disk_mib: 5120, min_cores: 1 }
            network_models: [bridged, routed]
            install_modes: [post]
            billing: free
            license: none
            inputs:
                - key: port
                  type: port
                  default: 8080
                  label: { en: Port }
            outputs:
                - key: url
                  type: url
                  label: { en: Address }
            firewall:
                - { port: '{{ inputs.port }}', proto: tcp }
            lifecycle:
                install: { script: install.sh, timeout_minutes: 10 }
                uninstall: { script: uninstall.sh, timeout_minutes: 5 }

            YAML;

        $install = <<<'BASH'
            #!/bin/bash
            # Installs the app. Must be idempotent: it may run again on the same server.
            set -euo pipefail
            # shellcheck source=/dev/null
            . "$PROXMOTION_APP_DIR/proxmotion.sh"

            port=$(pm_input port)

            pm_step 10 "Installing packages"
            pm_pkg_install curl

            pm_step 80 "Configuring"
            # Write the app's configuration here; read inputs with pm_input, never interpolate them unchecked.

            pm_output url "http://$(pm_public_address):${port}/"

            BASH;

        $uninstall = <<<'BASH'
            #!/bin/bash
            # Removes what install.sh created. Must be idempotent.
            set -euo pipefail
            # shellcheck source=/dev/null
            . "$PROXMOTION_APP_DIR/proxmotion.sh"

            pm_step 50 "Removing the app"

            BASH;

        $readme = <<<MD
            # {$name}

            What the recipe installs and how it is configured.

            | Input  | Meaning                       |
            | ------ | ----------------------------- |
            | `port` | Port the app listens on       |

            Outputs: the app's address.

            MD;

        return ['recipe.yaml' => $yaml, 'install.sh' => $install, 'uninstall.sh' => $uninstall, 'README.md' => $readme];
    }
}
