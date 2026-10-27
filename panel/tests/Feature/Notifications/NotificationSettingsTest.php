<?php

namespace Tests\Feature\Notifications;

use App\Domain\Settings\PanelSettings;
use App\Enums\NotificationEvent;
use App\Models\AuditLog;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Admin\AdminTestCase;

/**
 * The notification page of Panel settings, the test mail and each
 * administrator's own choice of events (docs/adr/0044).
 */
class NotificationSettingsTest extends AdminTestCase
{
    public function test_the_panel_settings_page_saves_notification_settings()
    {
        $this->put(route('admin.settings.update', 'notifications'), [
            'notifications_enabled' => true,
            'notification_recipients' => "Ops@example.com\nnoc@example.com, ops@example.com",
            'notification_throttle_minutes' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(PanelSettings::get('notifications_enabled'));
        $this->assertSame(['ops@example.com', 'noc@example.com'], PanelSettings::get('notification_recipients'));
        $this->assertSame(30, PanelSettings::get('notification_throttle_minutes'));
    }

    public function test_it_refuses_invalid_recipients()
    {
        $this->put(route('admin.settings.update', 'notifications'), [
            'notifications_enabled' => true,
            'notification_recipients' => "ops@example.com\nnot-an-address",
            'notification_throttle_minutes' => 1,
        ])->assertSessionHasErrors(['notification_recipients', 'notification_throttle_minutes']);

        $eleven = implode("\n", array_map(fn (int $i) => "ops{$i}@example.com", range(1, 11)));

        $this->put(route('admin.settings.update', 'notifications'), [
            'notifications_enabled' => true,
            'notification_recipients' => $eleven,
            'notification_throttle_minutes' => 60,
        ])->assertSessionHasErrors(['notification_recipients']);

        $this->assertSame([], PanelSettings::get('notification_recipients'));
    }

    public function test_the_env_page_shows_the_mailer_but_no_credentials()
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'panel@example.com', 'mail.mailers.smtp.password' => 'smtp-secret']);

        $this->get(route('admin.settings.edit', 'environment'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('environment.mailer', 'smtp')
                ->where('environment.mail_from', 'panel@example.com'))
            ->assertDontSee('smtp-secret');
    }

    public function test_a_test_email_is_queued_to_the_administrator_and_audited()
    {
        Notification::fake();
        config(['proxmotion.notifications.enabled' => false]);

        $this->post(route('admin.settings.test-email'))->assertRedirect();

        Notification::assertSentTo($this->admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === null);
        $this->assertSame($this->admin->email, AuditLog::query()->where('action', 'notifications.test_sent')->sole()->properties['email']);
    }

    public function test_test_emails_are_rate_limited()
    {
        Notification::fake();

        foreach (range(1, 3) as $attempt) {
            $this->post(route('admin.settings.test-email'))->assertRedirect();
        }

        $this->post(route('admin.settings.test-email'))->assertTooManyRequests();
    }

    public function test_an_administrator_sees_the_default_events_until_choosing()
    {
        $this->get(route('notifications.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Notifications')
                ->has('events', count(NotificationEvent::cases()))
                ->where('selected', array_map(fn (NotificationEvent $event) => $event->value, NotificationEvent::defaults()))
                ->where('enabled', false));
    }

    public function test_an_administrator_chooses_their_events()
    {
        $this->put(route('notifications.update'), ['events' => ['usage_exceeded', 'task_failed']])
            ->assertRedirect(route('notifications.edit'));

        $this->assertSame(['task_failed', 'usage_exceeded'], $this->admin->refresh()->notification_events);
        $this->assertTrue($this->admin->wantsNotification(NotificationEvent::UsageExceeded));
        $this->assertFalse($this->admin->wantsNotification(NotificationEvent::AdminCreated));

        // None at all is a choice too.
        $this->put(route('notifications.update'), ['events' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $this->admin->refresh()->notificationEvents());

        $this->put(route('notifications.update'), ['events' => ['nope']])->assertSessionHasErrors(['events.0']);
    }
}
