<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\Storage;

/**
 * Whether a server can be a linked clone of a Proxmox template (docs/adr/0027).
 *
 * Proxmox makes a linked clone only from a template, on the storage its disks
 * are on (`storage` is for full clones only), and onto another node only when
 * that storage is shared. The storage must be able to clone a base volume:
 * thin LVM, ZFS, Ceph RBD, or qcow2 files on a directory-like storage.
 */
final class LinkedClones
{
    /** Storage types whose volumes can be the base of a linked clone. */
    private const BLOCK_TYPES = ['lvmthin', 'zfspool', 'rbd'];

    /** File storages: linked clones only of qcow2 images. */
    private const FILE_TYPES = ['dir', 'nfs', 'cifs'];

    /**
     * Why a linked clone cannot be made, or null when it can.
     *
     * @param  array<string, mixed>  $templateConfig  GET .../qemu/{template}/config
     */
    public function unavailableReason(Server $server, ProxmoxTemplate $template, array $templateConfig): ?string
    {
        $volumes = self::diskVolumes($templateConfig);

        if ($volumes === []) {
            return "Template {$template->vmid} has no disks to link to.";
        }

        $storages = array_values(array_unique(array_map(fn (string $volume) => strstr($volume, ':', true), $volumes)));

        if (count($storages) > 1) {
            return "The disks of template {$template->vmid} are on several storages (".implode(', ', $storages).').';
        }

        $storageName = $storages[0];

        if ($server->storage !== null && $server->storage->name !== $storageName) {
            return "The server's storage {$server->storage->name} is not the template's storage {$storageName}.";
        }

        $storage = Storage::query()->where('node_id', $server->node_id)->where('name', $storageName)->first();

        if ($storage === null || ! $storage->is_available) {
            return "Storage {$storageName} of template {$template->vmid} is not available on node {$server->node->name}.";
        }

        if ($template->node_id !== $server->node_id && ! $storage->is_shared) {
            return "Template {$template->vmid} is on node {$template->node->name} and its storage {$storageName} is not shared.";
        }

        if (in_array($storage->type, self::FILE_TYPES, true)) {
            foreach ($volumes as $volume) {
                if (! str_ends_with($volume, '.qcow2')) {
                    return "Storage {$storageName} ({$storage->type}) links only qcow2 disks; {$volume} is not one.";
                }
            }

            return null;
        }

        if (! in_array($storage->type, self::BLOCK_TYPES, true)) {
            return "Storage {$storageName} ({$storage->type}) does not support linked clones.";
        }

        return null;
    }

    /**
     * The volumes of the template's disks (CD-ROMs and the cloud-init drive left out).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function diskVolumes(array $config): array
    {
        $volumes = [];

        foreach ($config as $key => $value) {
            if (! is_string($value) || preg_match('/^(scsi|virtio|sata|ide)\d+$|^efidisk0$|^tpmstate0$/', (string) $key) !== 1) {
                continue;
            }

            $volume = explode(',', $value, 2)[0];

            if (str_contains($value, 'media=cdrom') || $volume === 'none' || ! str_contains($volume, ':')) {
                continue;
            }

            $volumes[] = $volume;
        }

        return $volumes;
    }
}
