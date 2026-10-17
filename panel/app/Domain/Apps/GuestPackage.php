<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Servers\GuestScriptFailed;
use App\Enums\IpVersion;
use App\Models\AppInstallation;
use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\Resources\GuestAgentResource;
use stdClass;

/**
 * Writes an installation's recipe version into its directory inside the
 * server (docs/adr/0015, 0016): every script and include of the version, the
 * shared helpers and runner, the install inputs and server.json. Writing it
 * again is harmless, so every install, uninstall and action does.
 */
final readonly class GuestPackage
{
    public function __construct(private RecipeLoader $recipes) {}

    /**
     * @throws ProxmoxException
     * @throws GuestScriptFailed
     */
    public function upload(GuestAgentResource $agent, Server $server, AppInstallation $installation): void
    {
        $directory = $installation->guestDirectory();
        $files = $this->recipes->library() + $installation->version->files + [
            'inputs.json' => self::json($installation->inputs === [] ? new stdClass : $installation->inputs),
            'server.json' => self::json($this->serverFacts($server) + [
                // run.sh compares them with /etc/os-release before any script runs (docs/adr/0016 §3).
                'os_release' => OsFamilies::osReleaseIds($installation->version->recipe()->families),
            ]),
        ];

        $directories = [$directory];

        foreach (array_keys($files) as $path) {
            if (str_contains($path, '/')) {
                $directories[] = $directory.'/'.dirname($path);
            }
        }

        $agent->run($server->vmid, ['mkdir', '-p', '-m', '700', ...array_values(array_unique($directories))]);

        foreach ($files as $path => $content) {
            $agent->writeFile($server->vmid, $directory.'/'.$path, $content);
        }
    }

    /**
     * Pretty JSON for a file inside the server, refused when it does not fit one guest agent write.
     *
     * @throws GuestScriptFailed
     */
    public static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (strlen($json) > GuestAgentResource::MAX_WRITE_BYTES) {
            throw new GuestScriptFailed('The app\'s inputs are too large.');
        }

        return $json;
    }

    /**
     * A fresh status, so a poll before the runner starts never sees an earlier run's outcome.
     */
    public static function startingStatus(): string
    {
        return self::json(['status' => 'installing', 'progress' => 0, 'step' => 'Starting', 'message' => '', 'updated_at' => time()]);
    }

    /**
     * What recipes may know about the server (server.json).
     *
     * @return array<string, string|null>
     */
    private function serverFacts(Server $server): array
    {
        $facts = ['hostname' => $server->hostname, 'primary_ipv4' => null, 'primary_ipv6' => null];

        foreach ([IpVersion::V4, IpVersion::V6] as $version) {
            $address = $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', $version))->value('address');
            $facts[$version === IpVersion::V4 ? 'primary_ipv4' : 'primary_ipv6'] = is_string($address) ? $address : null;
        }

        return $facts;
    }
}
