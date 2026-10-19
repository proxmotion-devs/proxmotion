<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\ServerStatus;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Collection;

/**
 * Compares an OS template with /etc/os-release of a running server built
 * from it, read through the guest agent (docs/adr/0024 §8, 0026). It only
 * reads and shows; nothing is saved from it.
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
     * What the server runs, next to the template's system and version. Only
     * for administrators to see (docs/adr/0026): nothing is changed or
     * suggested, the system list is theirs.
     *
     * @return array{template_system: string, server_system: string, version_matches: bool|null}
     */
    public function compare(OsTemplate $template, OsRelease $release): array
    {
        $template->loadMissing('osVersion.operatingSystem');

        return [
            'template_system' => $template->osVersion?->label() ?? trim("{$template->distribution} {$template->version}"),
            'server_system' => $release->prettyName ?? trim($release->id.' '.($release->versionId ?? '')),
            'version_matches' => $release->versionId === null ? null : self::sameVersion($template->version, $release->versionId),
        ];
    }

    /** Whether two versions agree, one perhaps with more parts ("12" and "12.7"). */
    private static function sameVersion(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b.'.') || str_starts_with($b, $a.'.');
    }
}
