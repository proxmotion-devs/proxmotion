<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * A new format 2 recipe (docs/adr/0016, 0018): recipe.yaml, install and
 * uninstall scripts and a README, for `apps:make` and new apps of the
 * admin editor.
 */
final class RecipeSkeleton
{
    /**
     * @return array<string, string> files by path
     */
    public static function files(string $slug, string $name): array
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
                os: { families: [debian, rhel] }
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
