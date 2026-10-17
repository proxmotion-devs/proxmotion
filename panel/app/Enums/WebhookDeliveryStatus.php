<?php

declare(strict_types=1);

namespace App\Enums;

enum WebhookDeliveryStatus: string
{
    /** Not sent yet, or waiting for the next attempt. */
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    /** Every attempt failed; an administrator can send it again. */
    case Failed = 'failed';
}
