<?php

namespace Tests\Feature\Notifications;

use App\Domain\Apps\AppInstallations;
use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Domain\Notifications\AdminNotifier;
use App\Enums\AppInstallationStatus;
use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\AppInstallation;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\Task;
use App\Models\UsageViolation;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\AdminAlert;
use App\Provisioning\TaskFailer;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Email notifications for administrators (docs/adr/0044).
 */
class AdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'proxmotion.notifications.enabled' => true,
            'proxmotion.notifications.recipients' => [],
            'proxmotion.notifications.throttle_minutes' => 60,
        ]);
        Notification::fake();

        $this->admin = User::factory()->create(['locale' => 'en']);
    }

    private function failTask(): Task
    {
        $server = Server::factory()->create(['hostname' => 'web1.example.com']);
        $task = new Task;
        $task->forceFill(['server_id' => $server->id, 'type' => TaskType::Reinstall, 'status' => TaskStatus::Running, 'started_at' => now(), 'steps' => []])->save();

        app(TaskFailer::class)->fail($task, 'Proxmox refused the clone.');

        return $task;
    }

    public function test_nothing_is_sent_while_notifications_are_off()
    {
        config(['proxmotion.notifications.enabled' => false]);

        $this->failTask();

        Notification::assertNothingSent();
    }

    public function test_a_failed_task_is_mailed_to_administrators_who_want_it()
    {
        $unsubscribed = User::factory()->create(['notification_events' => [NotificationEvent::AdminCreated->value]]);
        $unverified = User::factory()->unverified()->create();

        $this->failTask();

        Notification::assertSentTo($this->admin, AdminAlert::class, function (AdminAlert $alert) {
            $mail = $alert->toMail($this->admin);

            return $alert->event === NotificationEvent::TaskFailed
                && str_contains($mail->subject, 'web1.example.com')
                && in_array('Error: Proxmox refused the clone.', $mail->introLines, true)
                && str_contains((string) $mail->actionUrl, '/admin/servers/');
        });
        Notification::assertNotSentTo($unsubscribed, AdminAlert::class);
        Notification::assertNotSentTo($unverified, AdminAlert::class);
    }

    public function test_further_recipients_get_every_event_once()
    {
        config(['proxmotion.notifications.recipients' => ['ops@example.com', $this->admin->email]]);

        $this->failTask();

        Notification::assertSentTo($this->admin, AdminAlert::class);
        Notification::assertSentOnDemand(AdminAlert::class, fn (AdminAlert $alert, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'ops@example.com');
        Notification::assertSentTimes(AdminAlert::class, 2);
    }

    public function test_mail_is_written_in_the_administrators_language()
    {
        $this->admin->forceFill(['locale' => 'tr'])->save();

        $this->failTask();

        Notification::assertSentTo($this->admin, AdminAlert::class, function (AdminAlert $alert, array $channels, User $notifiable) {
            // Sending switches to the preferred language; Notification::fake() does not.
            $this->assertSame('tr', $notifiable->preferredLocale());
            app()->setLocale('tr');

            return str_contains($alert->toMail($notifiable)->subject, 'başarısız');
        });
    }

    public function test_repeated_events_are_throttled_per_cluster()
    {
        $cluster = Cluster::factory()->create();
        $notifier = app(AdminNotifier::class);
        $this->admin->forceFill(['notification_events' => [NotificationEvent::DriftDetected->value]])->save();

        $notifier->driftDetected($cluster, 3);
        $notifier->driftDetected($cluster, 1);
        $notifier->driftDetected(Cluster::factory()->create(), 2);

        Notification::assertSentTimes(AdminAlert::class, 2);

        $this->travel(61)->minutes();
        $notifier->driftDetected($cluster, 1);

        Notification::assertSentTimes(AdminAlert::class, 3);
    }

    public function test_a_cluster_is_reported_only_when_it_stops_answering()
    {
        Http::preventStrayRequests()->fake(['*' => Http::response(['data' => null], 401)]);
        $cluster = Cluster::factory()->create(['last_synced_at' => now()->subMinutes(5), 'last_sync_error' => null]);

        foreach ([1, 2] as $attempt) {
            try {
                app(ClusterSynchronizer::class)->sync($cluster->refresh());
            } catch (ProxmoxException) {
                // Recorded on the cluster.
            }
        }

        Notification::assertSentTimes(AdminAlert::class, 1);
        Notification::assertSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::ClusterUnreachable);
    }

    public function test_a_failed_app_install_is_reported_but_not_a_trial()
    {
        $this->admin->forceFill(['notification_events' => [NotificationEvent::AppFailed->value]])->save();
        $installations = app(AppInstallations::class);

        $trial = AppInstallation::factory()->create(['status' => AppInstallationStatus::Installing, 'is_trial' => true]);
        $installations->failed($trial, 'Script exited with 1.');
        Notification::assertNothingSent();

        $installation = AppInstallation::factory()->create(['status' => AppInstallationStatus::Installing]);
        $installations->failed($installation, 'Script exited with 1.');

        Notification::assertSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::AppFailed);
    }

    public function test_errors_from_guests_cannot_add_links_to_the_mail()
    {
        $this->admin->forceFill(['notification_events' => [NotificationEvent::AppFailed->value]])->save();
        $installation = AppInstallation::factory()->create(['status' => AppInstallationStatus::Installing]);

        app(AppInstallations::class)->failed($installation, 'Disk full. [Log in again](https://phish.example.com) ![x](https://phish.example.com/i.png) \\[x](https://phish.example.com) <a href="https://phish.example.com">here</a>');

        Notification::assertSentTo($this->admin, AdminAlert::class, function (AdminAlert $alert) {
            $html = (string) $alert->toMail($this->admin)->render();

            return str_contains($html, 'Disk full. [Log in again](https://phish.example.com) ![x](https://phish.example.com/i.png) [x](https://phish.example.com)')
                && ! preg_match('/<(a|img)\s[^>]*phish/', $html);
        });
    }

    public function test_a_usage_violation_is_reported_in_gb()
    {
        $this->admin->forceFill(['notification_events' => [NotificationEvent::UsageExceeded->value]])->save();
        $violation = new UsageViolation;
        $violation->forceFill([
            'server_id' => Server::factory()->create()->id,
            'kind' => UsageKind::Traffic,
            'period_start' => now()->startOfMonth()->toDateString(),
            'value' => 1_500_000_000_000,
            'threshold' => 1_000_000_000_000,
            'action' => UsageAction::Suspend,
        ])->save();

        app(AdminNotifier::class)->usageExceeded($violation);

        Notification::assertSentTo($this->admin, AdminAlert::class, function (AdminAlert $alert) {
            $lines = $alert->toMail($this->admin)->introLines;

            return str_contains($lines[0], '1500 of 1000 GB') && $lines[1] === 'The panel suspends the server.';
        });
    }

    public function test_a_webhook_that_gives_up_is_reported_but_not_a_test_delivery()
    {
        Queue::fake([DeliverWebhook::class]);
        Http::preventStrayRequests()->fake(['billing.example.com/*' => Http::response('', 500)]);
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://billing.example.com/hook']);
        $delivery = function () use ($endpoint) {
            $delivery = new WebhookDelivery;
            $delivery->forceFill([
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => (string) str()->uuid(),
                'event' => 'server.provisioned',
                'payload' => '{}',
                'status' => WebhookDeliveryStatus::Pending,
                'attempts' => DeliverWebhook::MAX_ATTEMPTS - 1,
            ])->save();

            return $delivery;
        };

        (new DeliverWebhook($delivery(), retry: false))->handle();
        Notification::assertNothingSent();

        (new DeliverWebhook($delivery()))->handle();
        Notification::assertSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::WebhookFailed);
    }

    public function test_creating_an_administrator_is_reported()
    {
        $this->actingAs(User::factory()->withTwoFactor()->create(['name' => 'Ada']));

        $this->post(route('admin.users.store'), [
            'name' => 'Grace',
            'email' => 'grace@example.com',
            'password' => 'a-long-password-123',
        ])->assertRedirect();

        Notification::assertSentTo($this->admin, AdminAlert::class, function (AdminAlert $alert) {
            return $alert->event === NotificationEvent::AdminCreated
                && $alert->toMail($this->admin)->introLines[0] === 'The administrator Ada created an administrator account for Grace (grace@example.com).';
        });
    }

    public function test_mails_are_queued_after_the_transaction_commits()
    {
        $alert = new AdminAlert(NotificationEvent::TaskFailed, 'Test email');

        $this->assertTrue($alert->afterCommit);
        $this->assertSame(3, $alert->tries);
        $this->assertSame(['mail'], $alert->via($this->admin));
    }
}
