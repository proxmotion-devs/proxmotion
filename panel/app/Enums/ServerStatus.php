<?php

declare(strict_types=1);

namespace App\Enums;

enum ServerStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminating = 'terminating';
    case Terminated = 'terminated';
    case Error = 'error';

    /**
     * Statuses whose servers still occupy node capacity, IPs and a VMID.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status !== self::Terminated));
    }

    /**
     * Whether a task is (or may be) running for the server right now.
     */
    public function isBusy(): bool
    {
        return in_array($this, [self::Pending, self::Provisioning, self::Terminating], true);
    }
}
