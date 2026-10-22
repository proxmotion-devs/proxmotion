<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Enums\AppInstallationStatus;
use App\Models\AppInstallation;
use App\Models\Server;

/**
 * The ports a server's apps declare in their recipes (`firewall:`), opened
 * while the server drops by default (docs/adr/0035 §4). `{{ inputs.x }}` is
 * the installation's input value.
 */
final class AppFirewallRules
{
    /** Installations whose app is (or may still be) on the server. */
    private const PRESENT = [
        AppInstallationStatus::Installing,
        AppInstallationStatus::Installed,
        AppInstallationStatus::Upgrading,
        AppInstallationStatus::UpgradeFailed,
        AppInstallationStatus::Uninstalling,
        AppInstallationStatus::UninstallFailed,
    ];

    /**
     * @return list<array{app: string, protocol: string, port: int}> without repeats
     */
    public function for(Server $server): array
    {
        $installations = AppInstallation::query()
            ->where('server_id', $server->id)
            ->whereIn('status', self::PRESENT)
            ->with(['recipe', 'version'])
            ->orderBy('id')
            ->get();

        $ports = [];

        foreach ($installations as $installation) {
            $definition = $installation->version->recipe()->definition;

            foreach (is_array($definition['firewall'] ?? null) ? $definition['firewall'] : [] as $entry) {
                $port = is_array($entry) ? self::value($entry['port'] ?? null, $installation) : null;
                $protocol = is_array($entry) ? self::value($entry['proto'] ?? null, $installation) : null;

                if (! is_numeric($port) || (int) $port < 1 || (int) $port > 65535 || ! in_array($protocol, ['tcp', 'udp'], true)) {
                    continue;
                }

                $ports[] = ['app' => $installation->recipe->slug, 'protocol' => (string) $protocol, 'port' => (int) $port];
            }
        }

        return array_values(array_unique($ports, SORT_REGULAR));
    }

    /**
     * The Proxmox rules of the server's app ports.
     *
     * @return list<array<string, string|int>>
     */
    public function proxmoxRules(Server $server): array
    {
        return array_map(fn (array $port) => [
            'type' => 'in',
            'action' => 'ACCEPT',
            'proto' => $port['protocol'],
            'dport' => (string) $port['port'],
            'enable' => 1,
            'comment' => "Proxmotion app: {$port['app']}",
        ], $this->for($server));
    }

    /**
     * A literal value, or the input "{{ inputs.key }}" refers to.
     */
    private static function value(mixed $value, AppInstallation $installation): string|int|null
    {
        if (is_string($value) && preg_match('/^\{\{ inputs\.([a-z0-9_]+) \}\}$/', $value, $match) === 1) {
            $input = $installation->inputs[$match[1]] ?? null;

            return is_string($input) || is_int($input) ? $input : null;
        }

        return is_string($value) || is_int($value) ? $value : null;
    }
}
