<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Events administrators can get email about (docs/adr/0044).
 */
enum NotificationEvent: string
{
    case TaskFailed = 'task_failed';
    case AppFailed = 'app_failed';
    case UsageExceeded = 'usage_exceeded';
    case DriftDetected = 'drift_detected';
    case ClusterUnreachable = 'cluster_unreachable';
    case WebhookFailed = 'webhook_failed';
    case AdminCreated = 'admin_created';

    /**
     * What an administrator gets before choosing events of their own.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::TaskFailed, self::ClusterUnreachable, self::WebhookFailed, self::AdminCreated];
    }

    /**
     * Admin label (English key).
     */
    public function label(): string
    {
        return match ($this) {
            self::TaskFailed => 'A server task failed',
            self::AppFailed => 'An app script failed',
            self::UsageExceeded => 'A server went over a quota or usage limit',
            self::DriftDetected => 'Drift was found between the panel and Proxmox',
            self::ClusterUnreachable => 'A cluster cannot be reached',
            self::WebhookFailed => 'A billing system did not receive a webhook',
            self::AdminCreated => 'An administrator account was created',
        };
    }
}
