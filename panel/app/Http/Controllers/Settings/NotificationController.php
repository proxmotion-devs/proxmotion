<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Settings\PanelSettings;
use App\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The events an administrator gets email about (docs/adr/0044).
 */
class NotificationController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/Notifications', [
            'events' => array_map(fn (NotificationEvent $event) => [
                'value' => $event->value,
                'label' => $event->label(),
            ], NotificationEvent::cases()),
            'selected' => array_map(fn (NotificationEvent $event) => $event->value, $user->notificationEvents()),
            'enabled' => (bool) PanelSettings::get('notifications_enabled'),
            'verified' => $user->email_verified_at !== null,
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $validated = $request->validate([
            'events' => ['present', 'array'],
            'events.*' => ['string', 'distinct', Rule::enum(NotificationEvent::class)],
        ]);

        // In the enum's order, so the stored list does not depend on the form's.
        $chosen = array_values(array_filter(
            NotificationEvent::cases(),
            fn (NotificationEvent $event) => in_array($event->value, $validated['events'], true),
        ));

        $user->forceFill(['notification_events' => array_map(fn (NotificationEvent $event) => $event->value, $chosen)])->save();

        $this->toast('success', 'Notification settings saved.');

        return to_route('notifications.edit');
    }
}
