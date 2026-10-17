<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Events sent to the webhook endpoints of a billing system (docs/adr/0013).
 */
enum WebhookEvent: string
{
    /** A test event sent from the admin panel; endpoints cannot subscribe to it. */
    case Ping = 'ping';

    case ServerProvisioned = 'server.provisioned';
    case ServerSuspended = 'server.suspended';
    case ServerUnsuspended = 'server.unsuspended';
    case ServerReinstalled = 'server.reinstalled';
    case ServerTerminated = 'server.terminated';

    /** Creating, reinstalling or terminating failed; the server is in the error state. */
    case ServerFailed = 'server.failed';

    /** Any task, including power actions, password resets and resource changes. */
    case TaskSucceeded = 'task.succeeded';
    case TaskFailed = 'task.failed';

    /** An app installation finished (docs/adr/0015). */
    case AppInstalled = 'app.installed';
    case AppFailed = 'app.failed';

    /**
     * Events an endpoint can subscribe to.
     *
     * @return list<self>
     */
    public static function subscribable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $event) => $event !== self::Ping));
    }

    /**
     * The server event of a finished task, if its type has one.
     */
    public static function forTask(TaskType $type, bool $succeeded): ?self
    {
        if (! $succeeded) {
            return in_array($type, [TaskType::Create, TaskType::Reinstall, TaskType::Terminate], true) ? self::ServerFailed : null;
        }

        return match ($type) {
            TaskType::Create => self::ServerProvisioned,
            TaskType::Suspend => self::ServerSuspended,
            TaskType::Unsuspend => self::ServerUnsuspended,
            TaskType::Reinstall => self::ServerReinstalled,
            TaskType::Terminate => self::ServerTerminated,
            default => null,
        };
    }

    /**
     * Admin form label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::Ping => 'Test event',
            self::ServerProvisioned => 'Server provisioned',
            self::ServerSuspended => 'Server suspended',
            self::ServerUnsuspended => 'Server unsuspended',
            self::ServerReinstalled => 'Server reinstalled',
            self::ServerTerminated => 'Server terminated',
            self::ServerFailed => 'Server failed (create, reinstall or terminate)',
            self::TaskSucceeded => 'Any task succeeded',
            self::TaskFailed => 'Any task failed',
            self::AppInstalled => 'App installed',
            self::AppFailed => 'App installation failed',
        };
    }
}
