<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Domain\Notifications\AdminNotifier;
use App\Enums\StorageHealth;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeDisk;
use App\Models\Storage;
use App\Proxmox\Data\DiskInfo;
use App\Proxmox\Data\StorageConfig;
use App\Proxmox\Data\ThinPoolInfo;
use App\Proxmox\Data\ZfsPoolInfo;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reads the health of a cluster's storages after its sync (docs/adr/0047):
 * the storage configuration, the zpools, thin pools and physical disks of
 * each online node, and the Ceph cluster Proxmox manages. What cannot be read
 * never fails the sync and keeps its last known state; node reads that failed
 * are recorded on the node. Administrators get one mail per cluster when
 * something got worse.
 */
final readonly class StorageHealthCollector
{
    /** SMART changes slowly and smartctl reads every disk, so disks are read at most this often per node. */
    public const int DISK_INTERVAL_MINUTES = 30;

    public function __construct(private AdminNotifier $notifier) {}

    /**
     * @param  array<string, Node>  $nodes  the synced nodes by name
     * @param  list<string>  $online  names of the nodes that answered
     */
    public function collect(Cluster $cluster, ProxmoxClient $client, array $nodes, array $online): void
    {
        $check = StorageHealthCheck::fromSettings();
        $configs = $this->configs($client);
        $this->readCeph($cluster, $client, $configs);

        /** @var list<array{0: string, 1?: array<string, string|int>}> $lines */
        $lines = [];
        /** @var list<string> $reportedShared shared storages appear once per node, but are mailed once */
        $reportedShared = [];

        foreach ($nodes as $name => $node) {
            $pools = in_array($name, $online, true) ? $this->readNode($client, $node, $check, $lines) : null;

            foreach ($node->storages()->get() as $storage) {
                $storageLines = [];
                $this->judgeStorage($storage, $configs, $pools, $cluster->ceph_health, $check, $storageLines, $node);

                if ($storageLines !== [] && ! in_array($storage->name, $reportedShared, true)) {
                    array_push($lines, ...$storageLines);

                    if ($storage->is_shared) {
                        $reportedShared[] = $storage->name;
                    }
                }
            }
        }

        if ($lines !== []) {
            $this->notifier->storageUnhealthy($cluster, $lines);
        }
    }

    /**
     * @return array<string, StorageConfig>|null null when it could not be read
     */
    private function configs(ProxmoxClient $client): ?array
    {
        try {
            return collect($client->cluster()->storageConfigs())->keyBy('name')->all();
        } catch (ProxmoxException) {
            return null;
        }
    }

    /**
     * Only when a storage uses the Ceph cluster Proxmox manages: elsewhere the call
     * fails. A failed read keeps the last known health.
     *
     * @param  array<string, StorageConfig>|null  $configs
     */
    private function readCeph(Cluster $cluster, ProxmoxClient $client, ?array $configs): void
    {
        if ($configs === null) {
            return;
        }

        if (! collect($configs)->contains(fn (StorageConfig $config) => $config->usesManagedCeph())) {
            $cluster->forceFill(['ceph_health' => null, 'ceph_checks' => null])->save();

            return;
        }

        try {
            $ceph = $client->cluster()->cephStatus();
        } catch (ProxmoxException) {
            return;
        }

        $cluster->forceFill(['ceph_health' => $ceph->health, 'ceph_checks' => $ceph->checks])->save();
    }

    /**
     * Reads the node's pools, and its disks when they are due, and stores its disks.
     *
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines
     * @return array{zfs: array<string, ZfsPoolInfo>, thin: array<string, ThinPoolInfo>}|null null when they could not be read
     */
    private function readNode(ProxmoxClient $client, Node $node, StorageHealthCheck $check, array &$lines): ?array
    {
        $diskKey = "storage-health:disks:{$node->id}";
        $readDisks = Cache::add($diskKey, true, now()->addMinutes(self::DISK_INTERVAL_MINUTES));

        try {
            $disks = $readDisks ? $client->nodes()->disks($node->name) : null;
            $zfs = $client->nodes()->zfsPools($node->name);
            $thin = $client->nodes()->thinPools($node->name);
        } catch (ProxmoxException $e) {
            // Tried again at the next sync.
            Cache::forget($diskKey);
            $node->forceFill(['disk_health_error' => Str::limit($e->getMessage(), 490)])->save();

            return null;
        }

        $node->forceFill(['disk_health_error' => null])->save();

        if ($disks !== null) {
            $this->storeDisks($node, $disks, $check, $lines);
        }

        return [
            'zfs' => collect($zfs)->keyBy('name')->all(),
            'thin' => collect($thin)->keyBy(fn (ThinPoolInfo $pool) => "{$pool->volumeGroup}/{$pool->name}")->all(),
        ];
    }

    /**
     * Disks are matched by serial number, which stays when Linux renames a
     * device after a reboot or a removed disk; by device path without one.
     *
     * @param  list<DiskInfo>  $disks
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines
     */
    private function storeDisks(Node $node, array $disks, StorageHealthCheck $check, array &$lines): void
    {
        $existing = $node->disks()->get()->keyBy(fn (NodeDisk $disk) => $disk->serial ?? $disk->devpath);

        foreach ($disks as $info) {
            /** @var NodeDisk $disk */
            $disk = $existing->get($info->serial ?? $info->devpath) ?? $node->disks()->make();
            $before = $disk->exists ? $disk->health : StorageHealth::Unknown;
            [$health, $problems] = $check->disk($info->smart, $info->wearout, previous: $before);

            $disk->forceFill([
                'devpath' => $info->devpath,
                'model' => $info->model,
                'serial' => $info->serial,
                'type' => $info->type,
                'size_bytes' => $info->sizeBytes,
                'used' => $info->used,
                'smart' => $info->smart,
                'wearout' => $info->wearout,
                'health' => $health,
                'health_problems' => $problems,
                'is_present' => true,
            ])->save();

            $this->reportDisk($lines, $before, $disk, $node);
        }

        // A disk that vanished may have failed: kept and shown until an administrator forgets it.
        $reported = array_map(fn (DiskInfo $info) => $info->serial ?? $info->devpath, $disks);

        foreach ($existing->reject(fn (NodeDisk $disk) => in_array($disk->serial ?? $disk->devpath, $reported, true)) as $disk) {
            $before = $disk->health;
            [$health, $problems] = $check->disk($disk->smart, $disk->wearout, present: false, previous: $before);

            $disk->forceFill(['health' => $health, 'health_problems' => $problems, 'is_present' => false])->save();

            $this->reportDisk($lines, $before, $disk, $node);
        }
    }

    /**
     * What could not be read this time keeps its last known value: the storage
     * configuration, the node's pools, or the Ceph health.
     *
     * @param  array<string, StorageConfig>|null  $configs
     * @param  array{zfs: array<string, ZfsPoolInfo>, thin: array<string, ThinPoolInfo>}|null  $pools
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines
     */
    private function judgeStorage(
        Storage $storage,
        ?array $configs,
        ?array $pools,
        ?string $cephHealth,
        StorageHealthCheck $check,
        array &$lines,
        Node $node,
    ): void {
        $poolState = $storage->pool_state;
        $metadataUsed = $storage->metadata_used_bytes;
        $metadataTotal = $storage->metadata_total_bytes;

        if ($configs !== null) {
            $config = $configs[$storage->name] ?? null;
            $zpool = $config?->zpool();
            $thinKey = $config !== null && $config->type === 'lvmthin' && $config->volumeGroup !== null && $config->thinPool !== null
                ? "{$config->volumeGroup}/{$config->thinPool}"
                : null;

            if ($config?->usesManagedCeph()) {
                $poolState = $cephHealth;
            } elseif ($zpool === null) {
                $poolState = null;
            } elseif ($pools !== null) {
                $poolState = ($pools['zfs'][$zpool] ?? null)?->health;
            }

            if ($thinKey === null) {
                $metadataUsed = $metadataTotal = null;
            } elseif ($pools !== null) {
                $thin = $pools['thin'][$thinKey] ?? null;
                $metadataUsed = $thin?->metadataUsedBytes;
                $metadataTotal = $thin?->metadataTotalBytes;
            }
        }

        $isCeph = $poolState !== null && str_starts_with($poolState, 'HEALTH_');
        $before = $storage->health;

        [$health, $problems] = $check->storage(
            $storage->is_available,
            $storage->used_bytes,
            $storage->total_bytes,
            $isCeph ? null : $poolState,
            $metadataUsed,
            $metadataTotal,
            $isCeph ? $poolState : null,
            previous: $before,
        );

        $storage->forceFill([
            'health' => $health,
            'health_problems' => $problems,
            'pool_state' => $poolState,
            'metadata_used_bytes' => $metadataUsed,
            'metadata_total_bytes' => $metadataTotal,
        ])->save();

        $this->report($lines, $before, $health, $problems, 'Storage :storage on node :node:', [
            'storage' => $storage->name,
            'node' => $node->name,
        ]);
    }

    /**
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines
     */
    private function reportDisk(array &$lines, StorageHealth $before, NodeDisk $disk, Node $node): void
    {
        $this->report($lines, $before, $disk->health, $disk->health_problems ?? [], 'Disk :disk (:model) on node :node:', [
            'disk' => $disk->devpath,
            'model' => $disk->model ?? '—',
            'node' => $node->name,
        ]);
    }

    /**
     * Adds the mail lines of something that got worse.
     *
     * @param  list<array{0: string, 1?: array<string, string|int>}>  $lines
     * @param  list<array{severity: string, message: string, replace: array<string, string|int>}>  $problems
     * @param  array<string, string|int>  $replace
     */
    private function report(array &$lines, StorageHealth $before, StorageHealth $after, array $problems, string $title, array $replace): void
    {
        if (! $after->isWorseThan($before)) {
            return;
        }

        $lines[] = [$title, $replace];

        foreach ($problems as $problem) {
            $lines[] = [$problem['message'], $problem['replace']];
        }
    }
}
