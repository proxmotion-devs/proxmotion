<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Models\Server;
use RuntimeException;

/**
 * The SSH keys inside a server could not be read: its guest agent does not
 * answer.
 */
class GuestSshKeysUnavailable extends RuntimeException
{
    public function __construct(public readonly Server $server)
    {
        parent::__construct("Could not read the SSH keys inside {$server->hostname}.");
    }

    public function translatedMessage(): string
    {
        return __('Could not read the SSH keys inside :hostname: its guest agent does not answer.', ['hostname' => $this->server->hostname]);
    }
}
