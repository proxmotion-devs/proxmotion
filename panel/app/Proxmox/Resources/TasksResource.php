<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\Data\Upid;
use App\Proxmox\ProxmoxClient;

final readonly class TasksResource
{
    public function __construct(private ProxmoxClient $client) {}

    /**
     * @return array{running: bool, exitStatus: string|null}
     */
    public function status(Upid $upid): array
    {
        $data = $this->client->get('/nodes/'.rawurlencode($upid->node).'/tasks/'.rawurlencode($upid->value).'/status');
        $data = is_array($data) ? $data : [];

        return [
            'running' => ($data['status'] ?? null) !== 'stopped',
            'exitStatus' => is_scalar($data['exitstatus'] ?? null) ? (string) $data['exitstatus'] : null,
        ];
    }
}
