<?php

declare(strict_types=1);

namespace App\Enums;

enum IpVersion: int
{
    case V4 = 4;
    case V6 = 6;
}
