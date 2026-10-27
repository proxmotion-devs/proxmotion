<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Settings\PanelSettings;
use App\Enums\NotificationEvent;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Models\AppInstallation;
use App\Models\AppRun;
use App\Models\Cluster;
use App\Models\Task;
use App\Models\UsageViolation;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Notifications\AdminAlert;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emails administrators about events (docs/adr/0044): each administrator gets
 * the events they chose, the further recipients of Panel settings get all of
 * them. Nothing is sent while notifications are off. A notification problem
 * never changes the outcome of the operation that caused it.
 */
final class AdminNotifier
{
    /** Errors in mails are shortened to this many characters. */
    private const int ERROR_LENGTH = 500;

    public function taskFailed(Task $task): void
    {
        $this->send(NotificationEvent::TaskFailed, function () use ($task) {
            $server = $task->server;
            $replace = ['type' => $task->type->value, 'server' => $server->vmName()];

            return new AdminAlert(
                NotificationEvent::TaskFailed,
                'Task ":type" failed on :server',
                $replace,
                [
                    ['The task ":type" of server :server failed.', $replace],
                    ['Error: :error', ['error' => self::error($task->error)]],
                ],
                route('admin.servers.show', $server),
            );
        });
    }

    /**
     * An installation attempt failed. Trials of drafts (docs/adr/0018 §7) are not reported.
     */
    public function appInstallFailed(AppInstallation $installation): void
    {
        $this->send(NotificationEvent::AppFailed, function () use ($installation) {
            if ($installation->is_trial) {
                return null;
            }

            $server = $installation->server;
            $replace = ['app' => $installation->recipe->name, 'server' => $server->vmName()];

            return new AdminAlert(
                NotificationEvent::AppFailed,
                'Installing :app failed on :server',
                $replace,
                [
                    ['Installing the app :app on server :server failed.', $replace],
                    ['Error: :error', ['error' => self::error($installation->error)]],
                ],
                route('admin.servers.show', $server),
            );
        });
    }

    /**
     * An uninstall, upgrade or action failed.
     */
    public function appRunFailed(AppRun $run): void
    {
        $this->send(NotificationEvent::AppFailed, function () use ($run) {
            $installation = $run->installation;

            if ($installation->is_trial) {
                return null;
            }

            $server = $installation->server;
            $replace = ['kind' => $run->kind->value, 'app' => $installation->recipe->name, 'server' => $server->vmName()];

            return new AdminAlert(
                NotificationEvent::AppFailed,
                'App script ":kind" of :app failed on :server',
                $replace,
                [
                    ['The ":kind" run of the app :app on server :server failed.', $replace],
                    ['Error: :error', ['error' => self::error($run->error)]],
                ],
                route('admin.servers.show', $server),
            );
        });
    }

    public function usageExceeded(UsageViolation $violation): void
    {
        $this->send(NotificationEvent::UsageExceeded, function () use ($violation) {
            $server = $violation->server;
            // Traffic is counted in bytes; mails show decimal GB like the billing modules.
            $traffic = $violation->kind === UsageKind::Traffic;
            $divisor = $traffic ? 1_000_000_000 : 1;

            return new AdminAlert(
                NotificationEvent::UsageExceeded,
                ':server is over its :kind',
                ['server' => $server->vmName(), 'kind' => $violation->kind->label()],
                [
                    ['Server :server went over its :kind: :value of :threshold :unit.', [
                        'server' => $server->vmName(),
                        'kind' => $violation->kind->label(),
                        'value' => self::number($violation->value / $divisor),
                        'threshold' => self::number($violation->threshold / $divisor),
                        'unit' => $traffic ? 'GB' : $violation->kind->unit(),
                    ]],
                    [match ($violation->action) {
                        UsageAction::None => 'The panel only recorded it.',
                        UsageAction::Stop => 'The panel stops the server.',
                        UsageAction::Suspend => 'The panel suspends the server.',
                    }],
                ],
                route('admin.servers.show', $server),
            );
        });
    }

    /**
     * A sync found drift that was not open before: one mail per cluster per throttle window.
     */
    public function driftDetected(Cluster $cluster, int $count): void
    {
        $this->send(NotificationEvent::DriftDetected, fn () => new AdminAlert(
            NotificationEvent::DriftDetected,
            'Drift found on cluster :cluster',
            ['cluster' => $cluster->name],
            [['The last sync of cluster :cluster found :count new differences between the panel and Proxmox. Nothing was changed; review them in the drift report.', ['cluster' => $cluster->name, 'count' => $count]]],
            route('admin.drifts.index'),
        ), "cluster:{$cluster->id}");
    }

    /**
     * A sync failed after the previous one succeeded.
     */
    public function clusterUnreachable(Cluster $cluster, string $error): void
    {
        $this->send(NotificationEvent::ClusterUnreachable, fn () => new AdminAlert(
            NotificationEvent::ClusterUnreachable,
            'Cluster :cluster cannot be reached',
            ['cluster' => $cluster->name],
            [
                ['The panel could not sync cluster :cluster. New servers are not placed on it until a sync succeeds again.', ['cluster' => $cluster->name]],
                ['Error: :error', ['error' => self::error($error)]],
            ],
            route('admin.clusters.show', $cluster),
        ), "cluster:{$cluster->id}");
    }

    /**
     * A delivery gave up after its last attempt: one mail per endpoint per throttle window.
     */
    public function webhookFailed(WebhookDelivery $delivery): void
    {
        $this->send(NotificationEvent::WebhookFailed, function () use ($delivery) {
            $billingSystem = $delivery->endpoint->billingSystem->name;

            return new AdminAlert(
                NotificationEvent::WebhookFailed,
                'A webhook to :billing_system was not delivered',
                ['billing_system' => $billingSystem],
                [
                    ['The event :event could not be delivered to the webhook endpoint of :billing_system after :attempts attempts.', [
                        'event' => $delivery->event,
                        'billing_system' => $billingSystem,
                        'attempts' => $delivery->attempts,
                    ]],
                    ['Error: :error', ['error' => self::error($delivery->error)]],
                ],
                route('admin.billing-systems.index'),
            );
        }, "endpoint:{$delivery->webhook_endpoint_id}");
    }

    public function adminCreated(User $user, User $by): void
    {
        $this->send(NotificationEvent::AdminCreated, fn () => new AdminAlert(
            NotificationEvent::AdminCreated,
            'New administrator :email',
            ['email' => $user->email],
            [['The administrator :by created an administrator account for :name (:email).', ['by' => $by->name, 'name' => $user->name, 'email' => $user->email]]],
            route('admin.users.index'),
        ));
    }

    /**
     * A test mail through the configured mailer, also while notifications are off.
     */
    public function test(User $user): void
    {
        $user->notify(new AdminAlert(
            null,
            'Test email',
            [],
            [['This is a test email from the panel. If you can read it, the mail settings in .env work.']],
            route('admin.settings.edit', 'notifications'),
        ));
    }

    /**
     * @param  Closure(): (AdminAlert|null)  $alert  the mail, made only when it is sent; null to send none
     * @param  string|null  $throttle  events with the same key are sent once per throttle window
     */
    private function send(NotificationEvent $event, Closure $alert, ?string $throttle = null): void
    {
        try {
            if (! PanelSettings::get('notifications_enabled')) {
                return;
            }

            $notification = $alert();

            if ($notification === null) {
                return;
            }

            $minutes = (int) PanelSettings::get('notification_throttle_minutes');

            if ($throttle !== null && ! Cache::add("notifications:{$event->value}:{$throttle}", true, now()->addMinutes($minutes))) {
                return;
            }

            $admins = User::query()
                ->whereNotNull('email_verified_at')
                ->get()
                ->filter(fn (User $user) => $user->wantsNotification($event));

            Notification::send($admins, $notification);

            /** @var list<string> $recipients */
            $recipients = PanelSettings::get('notification_recipients');

            // Not twice to an administrator who already got it.
            foreach (array_diff($recipients, $admins->pluck('email')->all()) as $address) {
                Notification::route('mail', $address)->notify($notification);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function error(?string $error): string
    {
        return Str::limit($error ?? 'unknown', self::ERROR_LENGTH);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
