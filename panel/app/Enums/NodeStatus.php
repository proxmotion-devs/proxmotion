<?php

declare(strict_types=1);

namespace App\Enums;

enum NodeStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unknown = 'unknown';
}
