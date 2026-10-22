<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Models\Server;
use RuntimeException;

/**
 * /etc/os-release of a server could not be read: its guest agent does not
 * answer, or the file is missing or empty.
 */
class OsReleaseUnavailable extends RuntimeException
{
    public function __construct(public readonly Server $server)
    {
        parent::__construct("Could not read /etc/os-release of {$server->hostname}.");
    }

    public function translatedMessage(): string
    {
        return __('Could not read /etc/os-release of :hostname: its guest agent does not answer, or the file is missing.', ['hostname' => $this->server->hostname]);
    }
}
