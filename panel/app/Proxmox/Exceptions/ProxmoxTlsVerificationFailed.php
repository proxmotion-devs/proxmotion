<?php

declare(strict_types=1);

namespace App\Proxmox\Exceptions;

/**
 * The server answered, but its TLS certificate could not be verified
 * (typically Proxmox's default self-signed certificate).
 */
class ProxmoxTlsVerificationFailed extends ProxmoxUnreachable
{
    public static function forHost(string $host): self
    {
        return new self(sprintf(
            'The TLS certificate of %s could not be verified. Install a valid certificate or disable certificate verification for this cluster.',
            $host,
        ));
    }
}
