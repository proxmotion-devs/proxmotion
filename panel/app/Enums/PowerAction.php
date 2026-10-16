<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Power actions admins and customers can start for an active server.
 */
enum PowerAction: string
{
    case Start = 'start';
    case Shutdown = 'shutdown';
    case Stop = 'stop';
    case Reboot = 'reboot';

    public function taskType(): TaskType
    {
        return match ($this) {
            self::Start => TaskType::Start,
            self::Shutdown => TaskType::Shutdown,
            self::Stop => TaskType::Stop,
            self::Reboot => TaskType::Reboot,
        };
    }

    /**
     * Flash message shown when the task was queued (English key).
     */
    public function startedMessage(): string
    {
        return match ($this) {
            self::Start => 'Starting the server…',
            self::Shutdown => 'Shutting down the server…',
            self::Stop => 'Stopping the server…',
            self::Reboot => 'Rebooting the server…',
        };
    }
}
