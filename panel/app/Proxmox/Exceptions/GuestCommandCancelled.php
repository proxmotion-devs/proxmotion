<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

use RuntimeException;

/**
 * GuestAgentResource::run() stopped waiting because the caller cancelled the
 * command; it may still run in the guest. Not a ProxmoxException: nothing
 * went wrong on Proxmox, and retries of agent errors must not catch it.
 */
final class GuestCommandCancelled extends RuntimeException
{
    public function __construct(public readonly int $pid)
    {
        parent::__construct("The guest command {$pid} was cancelled.");
    }
}
