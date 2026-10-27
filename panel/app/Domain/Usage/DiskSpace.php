<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Models\Server;
use App\Proxmox\Data\GuestFileSystem;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Facades\Cache;

/**
 * How full a server's file systems are (docs/adr/0036 §7). Proxmox reports no disk
 * usage for QEMU VMs, so it comes from the guest agent, asked at most once per
 * FRESH_SECONDS for each server; a guest agent that did not answer is not asked
 * again sooner either.
 *
 * @phpstan-type FileSystem array{mountpoint: string, type: string, used_bytes: int, total_bytes: int}
 * @phpstan-type Usage array{time: int, available: bool, file_systems: list<FileSystem>}
 */
final class DiskSpace
{
    public const int FRESH_SECONDS = 60;

    /** At most this many file systems are listed, the root one first. */
    public const int MAX_FILE_SYSTEMS = 10;

    /** Read-only images and optical media (snaps, the cloud-init drive): always full, never the server's space. */
    private const array SKIPPED_TYPES = ['squashfs', 'iso9660', 'udf', 'cdfs'];

    public function __construct(private readonly ProxmoxClientFactory $clients) {}

    /**
     * The file systems of a server whose VM is running.
     *
     * @return Usage
     */
    public function current(Server $server): array
    {
        $usage = Cache::get($this->key($server));

        if (is_array($usage)) {
            /** @var Usage $usage */
            return $usage;
        }

        try {
            $fileSystems = $this->clients->forCluster($server->cluster()->firstOrFail())
                ->guestAgent($server->node->name)
                ->fileSystems($server->vmid);
            $usage = ['time' => now()->getTimestampMs(), 'available' => true, 'file_systems' => self::summarize($fileSystems)];
        } catch (ProxmoxException) {
            $usage = ['time' => now()->getTimestampMs(), 'available' => false, 'file_systems' => []];
        }

        Cache::put($this->key($server), $usage, self::FRESH_SECONDS);

        return $usage;
    }

    /**
     * The file systems worth showing, the root one (/ or C:) first, then by mount point.
     *
     * @param  list<GuestFileSystem>  $fileSystems
     * @return list<FileSystem>
     */
    public static function summarize(array $fileSystems): array
    {
        /** @var array<string, GuestFileSystem> $kept */
        $kept = [];

        foreach ($fileSystems as $fileSystem) {
            if ($fileSystem->usedBytes === null || $fileSystem->totalBytes === null || $fileSystem->totalBytes <= 0
                || $fileSystem->mountpoint === ''
                // Windows volumes without a drive letter (System Reserved, recovery).
                || str_starts_with($fileSystem->mountpoint, '\\\\?\\')
                || in_array(strtolower($fileSystem->type), self::SKIPPED_TYPES, true)) {
                continue;
            }

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
