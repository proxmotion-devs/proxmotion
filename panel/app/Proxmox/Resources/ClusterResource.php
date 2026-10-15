<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\ClusterStatus;
use App\Proxmox\Data\StorageInfo;
use App\Proxmox\Data\VmResource;
use App\Proxmox\ProxmoxClient;

final readonly class ClusterResource
{
    public function __construct(private ProxmoxClient $client) {}

    public function status(): ClusterStatus
    {
        return ClusterStatus::fromEntries(self::listOfArrays($this->client->get('/cluster/status')));
    }

    /**
     * @return list<StorageInfo>
     */
    public function storages(): array
    {
        return array_map(
            StorageInfo::fromArray(...),
            self::listOfArrays($this->client->get('/cluster/resources', ['type' => 'storage'])),
        );
    }

    /**
     * @return list<VmResource>
     */
    public function guests(): array
    {
        return array_map(
            VmResource::fromArray(...),
            self::listOfArrays($this->client->get('/cluster/resources', ['type' => 'vm'])),
        );
    }

    public function nextVmid(): int
    {
        $data = $this->client->get('/cluster/nextid');

        return is_numeric($data) ? (int) $data : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOfArrays(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return array_values(array_filter($data, is_array(...)));
    }
}
