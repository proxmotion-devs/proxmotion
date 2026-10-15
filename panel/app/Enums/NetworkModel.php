<?php

declare(strict_types=1);

namespace App\Enums;

enum NetworkModel: string
{
    case Bridged = 'bridged';
    case Routed = 'routed';
    case Nat = 'nat';
}
