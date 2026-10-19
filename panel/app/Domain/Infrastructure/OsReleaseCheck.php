<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\OsFamily;
use App\Enums\ServerStatus;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Collection;

/**
 * Compares an OS template with /etc/os-release of a running server built
 * from it, read through the guest agent (docs/adr/0024 §8). It only reads
 * and suggests: the administrator decides what to save.
 */
final readonly class OsReleaseCheck
{
    /** Where os-release(5) puts the file; the second is the fallback it names. */
    public const array PATHS = ['/etc/os-release', '/usr/lib/os-release'];

    /** At most this many servers are offered to check against. */
    public const int SERVERS = 20;

    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * Active servers of the template, test servers first, then the newest.
     *
     * @return Collection<int, Server>
     */
    public function servers(OsTemplate $template): Collection
    {
        return Server::query()
            ->where('os_template_id', $template->id)
            ->where('status', ServerStatus::Active)
            ->with(['node:id,name', 'cluster'])
            ->orderByDesc('is_test')
            ->orderByDesc('id')
            ->limit(self::SERVERS)
            ->get();
    }

    /**
     * @throws OsReleaseUnavailable when the guest agent does not answer or the file is missing
     */
    public function read(Server $server): OsRelease
    {
        $agent = $this->clients->forCluster($server->cluster)->guestAgent($server->node->name);

        foreach (self::PATHS as $path) {
            try {
                $file = $agent->readFile($server->vmid, $path);
            } catch (ProxmoxException) {
                continue;
            }

            if (trim($file->content) !== '') {
                return OsRelease::parse($file->content);
            }
        }

        throw new OsReleaseUnavailable($server);
    }

    /**
     * What the server says the template is, and which of its fields differ.
     *
     * @return array{suggestion: array{distribution: string, version: string, family: string, compatible_with: string|null, compatible_version: string|null}, differences: list<array{field: string, template: string|null, server: string|null}>}
     */
    public function compare(OsTemplate $template, OsRelease $release): array
    {
        $distribution = preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', $release->id) === 1 ? $release->id : 'linux';
        // Debian's VERSION_ID is "12" for 12.7: a template version that only adds parts is kept.
        $version = $release->versionId === null || self::sameVersion($template->version, $release->versionId) ? $template->version : $release->versionId;
        $family = Distributions::family($distribution) ?? OsFamily::fromOsReleaseIds([$distribution, ...$release->idLike]);
        $base = Distributions::base($distribution);

        if ($base !== null) {
            $compatibleWith = $base;
            $compatibleVersion = match (true) {
                $template->compatible_with === $base && $template->compatible_version !== null => $template->compatible_version,
                Distributions::sameVersion($distribution) => $version,
                default => null,
            };
        } elseif (! Distributions::known($distribution) && $template->compatible_with !== null && Distributions::family($template->compatible_with) === $family) {
            [$compatibleWith, $compatibleVersion] = [$template->compatible_with, $template->compatible_version];
        } else {
            [$compatibleWith, $compatibleVersion] = [null, null];
        }

        $suggestion = [
            'distribution' => $distribution,
            'version' => $version,
            'family' => $family->value,
            'compatible_with' => $compatibleWith,
            'compatible_version' => $compatibleVersion,
        ];
        $current = [
            'distribution' => $template->distribution,
            'version' => $template->version,
            'family' => $template->family->value,
            'compatible_with' => $template->compatible_with,
            'compatible_version' => $template->compatible_version,
        ];
        $differences = [];

        foreach ($suggestion as $field => $value) {
            // The version a distribution is compatible with is not in os-release: only a missing one counts.
            if ($field === 'compatible_version' && ($value === null || $current[$field] !== null)) {
                continue;
            }

            if ($current[$field] !== $value) {
                $differences[] = ['field' => $field, 'template' => $current[$field], 'server' => $value];
            }
        }

        return ['suggestion' => $suggestion, 'differences' => $differences];
    }

    /** Whether two versions agree, one perhaps with more parts ("12" and "12.7"). */
    private static function sameVersion(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b.'.') || str_starts_with($b, $a.'.');
    }
}
