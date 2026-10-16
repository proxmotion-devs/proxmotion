<?php

declare(strict_types=1);

namespace App\Proxmox\Resources;

use App\Proxmox\ProxmoxClient;

final readonly class AccessResource
{
    public function __construct(private ProxmoxClient $client) {}

    /**
     * Privileges the API token itself has on a path (inherited ACLs included).
     *
     * Proxmox answers { path: { privilege: propagate } }: the value is the
     * propagate flag, not whether the privilege is granted. Privileges a VM
     * gets from its pool, or that were granted without propagation, come with
     * 0 and are still granted.
     *
     * @return list<string>
     */
    public function privilegesOn(string $path): array
    {
        $data = $this->client->get('/access/permissions', ['path' => $path]);
        $privileges = is_array($data) && is_array($data[$path] ?? null) ? $data[$path] : [];

        return array_map(strval(...), array_keys($privileges));
    }
}
