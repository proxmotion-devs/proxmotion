<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

use SensitiveParameter;
use UnexpectedValueException;

/**
 * The answer of POST /nodes/{node}/qemu/{vmid}/vncproxy: a VNC listener on the
 * node that waits briefly for a vncwebsocket connection.
 */
final readonly class VncProxyTicket
{
    public function __construct(
        public int $port,
        #[SensitiveParameter] public string $ticket,
        #[SensitiveParameter] public string $password,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $port = $data['port'] ?? null;
        $ticket = $data['ticket'] ?? null;
        $password = $data['password'] ?? null;

        if (! is_numeric($port) || ! is_string($ticket) || $ticket === '' || ! is_string($password) || $password === '') {
            throw new UnexpectedValueException('Proxmox returned an incomplete vncproxy answer.');
        }

        return new self((int) $port, $ticket, $password);
    }

    /**
     * Keep the ticket and password out of dumps and logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['port' => $this->port, 'ticket' => '********', 'password' => '********'];
    }
}
