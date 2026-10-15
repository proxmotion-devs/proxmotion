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
     * @return list<string>
     */
    public function privilegesOn(string $path): array
    {
        $data = $this->client->get('/access/permissions', ['path' => $path]);
        $privileges = is_array($data) && is_array($data[$path] ?? null) ? $data[$path] : [];

        return array_map(strval(...), array_keys(array_filter($privileges)));
    }
}
