<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * What the browser needs to connect: the single-use ticket for the console
 * proxy and the one-time VNC password.
 */
final readonly class ConsoleTicket
{
    public function __construct(
        #[SensitiveParameter] public string $ticket,
        #[SensitiveParameter] public string $password,
        public Carbon $expiresAt,
    ) {}
}
