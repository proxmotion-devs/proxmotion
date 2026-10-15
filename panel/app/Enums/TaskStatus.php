<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
