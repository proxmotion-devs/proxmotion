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
        // Moving servers to other nodes and clusters (docs/adr/0037).
        'VM.Migrate',
    ];

    /**
     * What the incoming move token may do besides receiving servers on /vms:
     * nothing that reaches into the VMs (docs/adr/0037 §5).
     */
    private const INCOMING_FORBIDDEN_ON_VMS = ['VM.PowerMgmt', 'VM.Console', 'VM.Monitor', 'VM.GuestAgent.Unrestricted', 'VM.Config.Disk'];

    /**
     * Privileges that let a token take over the whole Proxmox installation.
     */
    public const DANGEROUS_PRIVILEGES = ['Permissions.Modify', 'User.Modify', 'Realm.Allocate', 'Sys.Modify', 'Sys.Console'];

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
     * The same for the cluster's incoming move token (docs/adr/0037 §5):
     * receiving a remote migration needs Sys.Incoming on / and VM.Allocate on
     * /vms (the new VMID is in no pool yet), putting the VM into the resource
     * pool Pool.Allocate. Empty without an incoming token.
     *
     * @return list<array{path: string, purpose: string, missing: list<string>, excess: list<string>}>
     */
    public function checkIncoming(Cluster $cluster): array
    {
        $connection = $cluster->incomingConnection();

        if ($connection === null) {
            return [];
        }

        $access = $this->clients->make($connection)->access();
        $requirements = [
            ['/', 'Receive servers from other clusters', ['Sys.Incoming', 'Sys.Audit'], self::DANGEROUS_PRIVILEGES],
            ['/vms', 'Create the moved VM', ['VM.Allocate'], self::INCOMING_FORBIDDEN_ON_VMS],
            ['/storage', 'Allocate the copied disks', ['Datastore.AllocateSpace'], []],
            ['/sdn/zones/localnetwork', 'Attach the moved VM to a bridge', ['SDN.Use'], []],
        ];

        if ($cluster->resource_pool !== null) {
            $requirements[] = ["/pool/{$cluster->resource_pool}", 'Put the moved VM into the resource pool', ['Pool.Allocate'], []];
        }

        return array_map(function (array $requirement) use ($access) {
            [$path, $purpose, $required, $forbidden] = $requirement;
            $granted = $access->privilegesOn($path);

            return [
                'path' => $path,
                'purpose' => $purpose,
                'missing' => array_values(array_diff($required, $granted)),
                'excess' => array_values(array_intersect($forbidden, $granted)),
            ];
        }, $requirements);
    }

    /**
     * Guest agent commands (exec, file-write, set-user-password) need
     * VM.GuestAgent.Unrestricted since Proxmox VE 9 (per the API viewer);
     * Proxmox VE 8 checked VM.Monitor instead (not verified on a real 8.x).
     */
    private static function guestAgentPrivilege(?string $pveVersion): string
    {
        $major = $pveVersion !== null && preg_match('/^(\d+)\./', $pveVersion, $m) === 1 ? (int) $m[1] : null;

        return $major !== null && $major < 9 ? 'VM.Monitor' : 'VM.GuestAgent.Unrestricted';
    }

    /**
     * @return list<array{string, string, list<string>, list<string>}>
     */
    private function requirements(Cluster $cluster): array
    {
        $pool = $cluster->resource_pool;
        $vmPath = $pool !== null ? "/pool/{$pool}" : '/vms';
        $vmPrivileges = [...self::VM_PRIVILEGES, self::guestAgentPrivilege($cluster->pve_version)];
        $vmPrivileges = $pool !== null ? [...$vmPrivileges, 'Pool.Allocate'] : $vmPrivileges;

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

        // Templates the panel clones (mapped to an OS template) must be clonable — with a
        // resource pool, that means they must be in it. Other templates on the cluster are not the panel's business.
        foreach ($cluster->proxmoxTemplates()->whereHas('osTemplates')->orderBy('vmid')->pluck('vmid') as $vmid) {
            $requirements[] = ["/vms/{$vmid}", 'Clone this template (it must be in the resource pool)', ['VM.Clone'], []];
        }

        return $requirements;
    }
}
