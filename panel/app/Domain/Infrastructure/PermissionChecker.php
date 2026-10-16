<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Models\Cluster;
use App\Proxmox\ProxmoxClientFactory;

/**
 * Asks Proxmox which privileges the cluster's API token has, lists the ones the
 * panel needs but doesn't have, and flags privileges the token should NOT have
 * (least privilege: a leaked token must not be root-equivalent).
 */
final readonly class PermissionChecker
{
    private const VM_PRIVILEGES = [
        'VM.Audit', 'VM.Allocate', 'VM.Clone', 'VM.PowerMgmt', 'VM.Console',
        'VM.Config.CPU', 'VM.Config.Memory', 'VM.Config.Disk',
        'VM.Config.Network', 'VM.Config.Cloudinit', 'VM.Config.Options',
    ];

    /**
     * Privileges that let a token take over the whole Proxmox installation.
     */
    private const DANGEROUS_PRIVILEGES = ['Permissions.Modify', 'User.Modify', 'Realm.Allocate', 'Sys.Modify', 'Sys.Console'];

    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * @return list<array{path: string, purpose: string, missing: list<string>, excess: list<string>}>
     */
    public function check(Cluster $cluster): array
    {
        $access = $this->clients->forCluster($cluster)->access();
        $results = [];

        foreach ($this->requirements($cluster) as [$path, $purpose, $required, $forbidden]) {
            $granted = $access->privilegesOn($path);

            $results[] = [
                'path' => $path,
                'purpose' => $purpose,
                'missing' => array_values(array_diff($required, $granted)),
                'excess' => array_values(array_intersect($forbidden, $granted)),
            ];
        }

        return $results;
    }

    /**
     * @return list<array{string, string, list<string>, list<string>}>
     */
    private function requirements(Cluster $cluster): array
    {
        $pool = $cluster->resource_pool;
        $vmPath = $pool !== null ? "/pool/{$pool}" : '/vms';
        $vmPrivileges = $pool !== null ? [...self::VM_PRIVILEGES, 'Pool.Allocate'] : self::VM_PRIVILEGES;

        $requirements = [
            ['/', 'Read nodes, storages and networks', ['Sys.Audit', 'Datastore.Audit', 'VM.Audit'], self::DANGEROUS_PRIVILEGES],
            [$vmPath, 'Create and manage servers', $vmPrivileges, []],
            ['/storage', 'Allocate server disks', ['Datastore.AllocateSpace'], []],
            ['/sdn/zones/localnetwork', 'Attach servers to bridges', ['SDN.Use'], []],
        ];

        if ($pool !== null) {
            // With a pool, the token must not be able to touch VMs outside of it.
            $requirements[] = ['/vms', 'Must not manage VMs outside the resource pool', [], ['VM.Allocate', 'VM.PowerMgmt', 'VM.Config.Disk']];
        }

        // Templates must be clonable too — with a resource pool, that means they must be in it.
        foreach ($cluster->proxmoxTemplates()->orderBy('vmid')->pluck('vmid') as $vmid) {
            $requirements[] = ["/vms/{$vmid}", 'Clone this template (it must be in the resource pool)', ['VM.Clone'], []];
        }

        return $requirements;
    }
}
