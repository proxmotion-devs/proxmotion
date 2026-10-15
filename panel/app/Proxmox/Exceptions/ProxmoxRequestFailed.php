<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

class ProxmoxRequestFailed extends ProxmoxException
{
    public function __construct(
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message, $status);
    }
}
