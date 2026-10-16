<?php

declare(strict_types=1);

namespace App\Enums;

enum OsPlatform: string
{
    case Linux = 'linux';
    case Windows = 'windows';

    /** The administrator account the guest agent sets the password of. */
    public function administrator(): string
    {
        return match ($this) {
            self::Linux => 'root',
            self::Windows => 'Administrator',
        };
    }
}
