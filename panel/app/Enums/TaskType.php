<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskType: string
{
    case Create = 'create';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case Terminate = 'terminate';
    case Adopt = 'adopt';
}
