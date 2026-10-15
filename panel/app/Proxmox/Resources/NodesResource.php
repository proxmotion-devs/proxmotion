<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\BridgeInfo;
use App\Proxmox\Data\NodeInfo;
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
}
