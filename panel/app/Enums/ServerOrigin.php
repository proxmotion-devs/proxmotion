<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerOrigin: string
{
    case Provisioned = 'provisioned';
    case Adopted = 'adopted';
}
