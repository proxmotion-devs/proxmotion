<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use RuntimeException;

/**
 * Rebuilding a server from scratch found a VM with its VMID that the panel
 * did not create for it (docs/adr/0032 §7): the panel never deletes such a VM.
 */
final class ForeignVm extends RuntimeException
{
    public static function at(int $vmid): self
    {
        return new self("VM {$vmid} was not created by Proxmotion for this server, so it is not deleted. Check it on Proxmox and delete it there if it is not needed, then try again.");
    }
}
