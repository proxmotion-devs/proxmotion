<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

class ProxmoxUnreachable extends ProxmoxException
{
    /**
     * @param  list<string>  $hosts
     */
    public static function allHostsFailed(array $hosts, string $reason): self
    {
        return new self(sprintf('Could not reach Proxmox at %s: %s', implode(', ', $hosts), $reason));
    }
}
