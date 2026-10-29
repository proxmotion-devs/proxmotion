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

    /** The server moved to another node, cluster or location (docs/adr/0037); its addresses may be new. */
    case ServerMoved = 'server.moved';

    /** The server went back to a snapshot or was restored from a backup (docs/adr/0051); its root password is the one of that time. */
    case ServerRestored = 'server.restored';

    /** Creating, reinstalling or terminating failed; the server is in the error state. */
    case ServerFailed = 'server.failed';

    /** The server went over its traffic quota or a usage limit (docs/adr/0039), whatever the panel does about it. */
    case ServerUsageExceeded = 'server.usage_exceeded';

    /** Any task, including power actions, password resets and resource changes. */
    case TaskSucceeded = 'task.succeeded';
    case TaskFailed = 'task.failed';

    /** An app installation finished (docs/adr/0015). */
    case AppInstalled = 'app.installed';
    case AppFailed = 'app.failed';

    /** An app was uninstalled, or an action of an installed app ran (docs/adr/0016). */
    case AppUninstalled = 'app.uninstalled';
    case AppUninstallFailed = 'app.uninstall_failed';
    case AppActionSucceeded = 'app.action_succeeded';
    case AppActionFailed = 'app.action_failed';

    /** An installed app was upgraded to a newer version of its recipe, or the upgrade failed (docs/adr/0016 §4). */
    case AppUpgraded = 'app.upgraded';
    case AppUpgradeFailed = 'app.upgrade_failed';

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
            TaskType::Move => self::ServerMoved,
            TaskType::RollbackSnapshot, TaskType::RestoreBackup => self::ServerRestored,
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
            self::ServerMoved => 'Server moved',
            self::ServerRestored => 'Server restored from a snapshot or backup',
            self::ServerFailed => 'Server failed (create, reinstall or terminate)',
            self::ServerUsageExceeded => 'Server over its traffic quota or a usage limit',
            self::TaskSucceeded => 'Any task succeeded',
            self::TaskFailed => 'Any task failed',
            self::AppInstalled => 'App installed',
            self::AppFailed => 'App installation failed',
            self::AppUninstalled => 'App uninstalled',
            self::AppUninstallFailed => 'App uninstall failed',
            self::AppActionSucceeded => 'App action succeeded',
            self::AppActionFailed => 'App action failed',
            self::AppUpgraded => 'App upgraded',
            self::AppUpgradeFailed => 'App upgrade failed',
        };
    }
}
