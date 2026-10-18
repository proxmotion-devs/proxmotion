<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who granted the right to install a paid app (docs/adr/0019).
 */
enum AppEntitlementSource: string
{
    /** The server was ordered with the app. */
    case Order = 'order';

    /** The billing system granted it through the API (e.g. an addon was bought). */
    case Api = 'api';

    case Admin = 'admin';
}
