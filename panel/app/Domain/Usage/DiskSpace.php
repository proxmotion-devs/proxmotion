<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Models\Server;
use App\Proxmox\Data\GuestFileSystem;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * How full a server's file systems are (docs/adr/0036 §7). Proxmox reports no disk
 * usage for QEMU VMs, so it comes from the guest agent. The page gets the last known
 * answer at once; one that is older than FRESH_SECONDS (RETRY_SECONDS when the agent
 * did not answer, as while the guest boots) is asked again after the response has
 * been sent, so a guest agent that hangs never holds the page's request.
 *
 * @phpstan-type FileSystem array{mountpoint: string, type: string, used_bytes: int, total_bytes: int}
 * @phpstan-type Usage array{time: int, available: bool, file_systems: list<FileSystem>}
 */
final class DiskSpace
{
    public const int FRESH_SECONDS = 60;

    /** A guest agent that did not answer is asked again after this long: it may still be starting. */
    public const int RETRY_SECONDS = 15;

    /** An answer older than this is no longer shown. */
    public const int KEEP_SECONDS = 300;

    /** At most this many file systems are listed, the root one first. */
    public const int MAX_FILE_SYSTEMS = 10;

    /** How long one refresh may run before another request may start the next one. */
    private const int REFRESH_LOCK_SECONDS = 60;

    /** Read-only images and optical media (snaps, the cloud-init drive): always full, never the server's space. */
    private const array SKIPPED_TYPES = ['squashfs', 'iso9660', 'udf', 'cdfs'];

    public function __construct(private readonly ProxmoxClientFactory $clients) {}

    /**
     * The last known file systems of a server whose VM is running; null until its guest
     * agent has been asked once. A stale answer is refreshed after the response.
     *
     * @return Usage|null
     */
    public function current(Server $server): ?array
    {
        $usage = Cache::get($this->key($server));
        $usage = is_array($usage) ? $usage : null;
        /** @var Usage|null $usage */
        $refreshAfter = ($usage['available'] ?? true) ? self::FRESH_SECONDS : self::RETRY_SECONDS;

        if ($usage === null || now()->getTimestampMs() - $usage['time'] >= $refreshAfter * 1000) {
            $this->refreshLater($server);
        }

        return $usage;
    }

    /**
     * Ask the guest agent now and keep its answer (or that it did not answer).
     *
     * @return Usage
     */
    public function refresh(Server $server): array
    {
        try {
            $fileSystems = $this->clients->forCluster($server->cluster()->firstOrFail())
                ->guestAgent($server->node->name)
                ->fileSystems($server->vmid);
            $usage = ['time' => now()->getTimestampMs(), 'available' => true, 'file_systems' => self::summarize($fileSystems)];
        } catch (ProxmoxException) {
            $usage = ['time' => now()->getTimestampMs(), 'available' => false, 'file_systems' => []];
        }

        Cache::put($this->key($server), $usage, self::KEEP_SECONDS);

        return $usage;
    }

    /**
     * Refresh once the response has been sent, one refresh per server at a time however
     * many pages ask.
     */
    private function refreshLater(Server $server): void
    {
        $lock = $this->key($server).':refreshing';

        if (! Cache::add($lock, true, self::REFRESH_LOCK_SECONDS)) {
            return;
        }

        defer(function () use ($server, $lock): void {
            try {
                $this->refresh($server);
            } finally {
                Cache::forget($lock);
            }
        });
    }

    /**
     * The file systems worth showing, the root one (/ or C:) first, then by mount point.
     *
     * @param  list<GuestFileSystem>  $fileSystems
     * @return list<FileSystem>
     */
    public static function summarize(array $fileSystems): array
    {
        /** @var array<string, GuestFileSystem> $visible */
        $visible = [];

        foreach ($fileSystems as $fileSystem) {
            if ($fileSystem->usedBytes === null || $fileSystem->totalBytes === null || $fileSystem->totalBytes <= 0
                || $fileSystem->mountpoint === ''
                // Windows volumes without a drive letter (System Reserved, recovery).
                || str_starts_with($fileSystem->mountpoint, '\\\\?\\')
                || in_array(strtolower($fileSystem->type), self::SKIPPED_TYPES, true)) {
                continue;
            }

            // The agent lists mounts in the order they were made: one mounted over a path hides the earlier one.
            $visible[$fileSystem->mountpoint] = $fileSystem;
        }

        /** @var array<string, GuestFileSystem> $kept */
        $kept = [];

        foreach ($visible as $fileSystem) {
            // Bind mounts and btrfs subvolumes report one device several times: keep its shortest mount point.
            $device = $fileSystem->name !== '' ? $fileSystem->name : $fileSystem->mountpoint;

            if (! isset($kept[$device]) || strlen($fileSystem->mountpoint) < strlen($kept[$device]->mountpoint)) {
                $kept[$device] = $fileSystem;
            }
        }

        $kept = array_values($kept);
        usort($kept, fn (GuestFileSystem $a, GuestFileSystem $b) => [! self::isRoot($a->mountpoint), $a->mountpoint] <=> [! self::isRoot($b->mountpoint), $b->mountpoint]);

        return array_map(fn (GuestFileSystem $fileSystem) => [
            'mountpoint' => $fileSystem->mountpoint,
            'type' => $fileSystem->type,
            'used_bytes' => (int) $fileSystem->usedBytes,
            'total_bytes' => (int) $fileSystem->totalBytes,
        ], array_slice($kept, 0, self::MAX_FILE_SYSTEMS));
    }

    private static function isRoot(string $mountpoint): bool
    {
        return $mountpoint === '/' || strcasecmp(rtrim($mountpoint, '\\'), 'C:') === 0;
    }

    private function key(Server $server): string
    {
        return "server-stats:disk:{$server->id}";
    }
}
