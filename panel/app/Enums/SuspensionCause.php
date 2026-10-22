<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the panel suspended a server itself (docs/adr/0039). Suspensions by
 * administrators and billing systems have none.
 */
enum SuspensionCause: string
{
    /** Over its traffic quota: unsuspended by the panel once it no longer is. */
    case Traffic = 'traffic';

    /** Over a usage limit: stays suspended until someone unsuspends it. */
    case Usage = 'usage';
}
