<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

use App\Proxmox\Data\Upid;

class ProxmoxTaskFailed extends ProxmoxException
{
    public static function withExitStatus(Upid $upid, string $exitStatus): self
    {
        return new self(sprintf('Proxmox task %s on %s failed: %s', $upid->type, $upid->node, $exitStatus));
    }

    public static function timedOut(Upid $upid, int $seconds): self
    {
        return new self(sprintf('Proxmox task %s on %s did not finish within %d seconds.', $upid->type, $upid->node, $seconds));
    }
}
