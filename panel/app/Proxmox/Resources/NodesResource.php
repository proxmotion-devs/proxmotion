<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\BridgeInfo;
use App\Proxmox\Data\DiskInfo;
use App\Proxmox\Data\NodeInfo;
use App\Proxmox\Data\ThinPoolInfo;
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
}
