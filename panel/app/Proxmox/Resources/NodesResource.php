<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\BackupVolume;
use App\Proxmox\Data\BridgeInfo;
use App\Proxmox\Data\DiskInfo;
use App\Proxmox\Data\NodeInfo;
use App\Proxmox\Data\ThinPoolInfo;
use App\Proxmox\Data\Upid;
use App\Proxmox\Data\ZfsPoolInfo;
use App\Proxmox\ProxmoxClient;

final readonly class NodesResource
{
    public function __construct(private ProxmoxClient $client) {}

    /**
     * @return list<NodeInfo>
     */
    public function list(): array
    {
        return array_map(
            NodeInfo::fromArray(...),
            ClusterResource::listOfArrays($this->client->get('/nodes')),
        );
    }

    /**
     * @return list<BridgeInfo>
     */
    public function bridges(string $node): array
    {
        return array_map(
            BridgeInfo::fromArray(...),
            ClusterResource::listOfArrays(
                $this->client->get('/nodes/'.rawurlencode($node).'/network', ['type' => 'any_bridge']),
            ),
        );
    }

    /**
     * The node's physical disks with their SMART result (Sys.Audit on the node).
     *
     * @return list<DiskInfo>
     */
    public function disks(string $node): array
    {
        return array_map(
            DiskInfo::fromArray(...),
            ClusterResource::listOfArrays($this->client->get('/nodes/'.rawurlencode($node).'/disks/list')),
        );
    }

    /**
     * @return list<ZfsPoolInfo>
     */
    public function zfsPools(string $node): array
    {
        return array_map(
            ZfsPoolInfo::fromArray(...),
            ClusterResource::listOfArrays($this->client->get('/nodes/'.rawurlencode($node).'/disks/zfs')),
        );
    }

    /**
     * @return list<ThinPoolInfo>
     */
    public function thinPools(string $node): array
    {
        return array_map(
            ThinPoolInfo::fromArray(...),
            ClusterResource::listOfArrays($this->client->get('/nodes/'.rawurlencode($node).'/disks/lvmthin')),
        );
    }

    /**
     * Back up one VM to a storage (docs/adr/0051 §4): in snapshot mode, so
     * it keeps running (the guest agent freezes its file systems), and
     * without pruning, which the panel does itself. Proxmox Backup Server
     * compresses on its own and takes no compress option.
     */
    public function vzdump(string $node, int $vmid, string $storage, bool $compress, string $notes): Upid
    {
        $params = [
            'vmid' => $vmid,
            'storage' => $storage,
            'mode' => 'snapshot',
            'remove' => 0,
            'notes-template' => $notes,
        ];

        if ($compress) {
            $params['compress'] = 'zstd';
        }

        $result = $this->client->post('/nodes/'.rawurlencode($node).'/vzdump', $params);

        return Upid::parse(is_string($result) ? $result : '');
    }

    /**
     * The backups of one VM on a storage.
     *
     * @return list<BackupVolume>
     */
    public function backups(string $node, string $storage, int $vmid): array
    {
        return array_map(
            BackupVolume::fromArray(...),
            array_values(array_filter(
                ClusterResource::listOfArrays($this->client->get(
                    '/nodes/'.rawurlencode($node).'/storage/'.rawurlencode($storage).'/content',
                    ['content' => 'backup', 'vmid' => $vmid],
                )),
                fn (array $entry) => is_string($entry['volid'] ?? null),
            )),
        );
    }

    /**
     * Delete a volume from a storage; recent Proxmox versions answer with a task.
     */
    public function deleteVolume(string $node, string $storage, string $volume): ?Upid
    {
        $result = $this->client->delete('/nodes/'.rawurlencode($node).'/storage/'.rawurlencode($storage).'/content/'.rawurlencode($volume));

        return is_string($result) && str_starts_with($result, 'UPID:') ? Upid::parse($result) : null;
    }
}
