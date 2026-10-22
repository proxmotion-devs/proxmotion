<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the panel does when a server exceeds its traffic quota or a usage
 * limit (docs/adr/0039). Traffic quotas offer none and suspend only.
 */
enum UsageAction: string
{
    /** Only recorded and shown (and sent as server.usage_exceeded). */
    case None = 'none';

    /** Forced power-off; the customer may start the server again. */
    case Stop = 'stop';

    case Suspend = 'suspend';
}
