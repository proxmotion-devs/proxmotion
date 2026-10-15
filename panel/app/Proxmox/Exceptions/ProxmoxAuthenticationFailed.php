<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

class ProxmoxAuthenticationFailed extends ProxmoxException
{
    public static function invalidToken(): self
    {
        return new self('Proxmox rejected the API token. Check the token ID, secret and its permissions.');
    }
}
