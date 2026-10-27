<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\AdminNotifier;
use App\Domain\Settings\PanelSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PanelSettingsRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel settings (docs/adr/0038): what used to need an .env change and a
 * restart, one page per category of PanelSettings::CATEGORIES. Security and
 * deployment settings stay in .env and are only shown.
 */
class PanelSettingsController extends Controller
{
    public function index(): RedirectResponse
    {
        return to_route('admin.settings.edit', array_key_first(PanelSettings::CATEGORIES));
    }

    public function edit(string $category): Response
    {
        $fields = PanelSettings::CATEGORIES[$category];

        return Inertia::render('admin/settings/Edit', [
            'category' => $category,
            'fields' => $fields,
            'settings' => PanelSettings::all(),
            'unsaved' => array_values(array_intersect($fields, PanelSettings::unsaved())),
            'environment' => [
                'require_admin_two_factor' => (bool) config('proxmotion.require_admin_two_factor'),
                'console_proxy' => filled(config('proxmotion.console.proxy_secret')),
                // The mail transport stays in .env (docs/adr/0044 §3); never its host's credentials.
                'mailer' => (string) config('mail.default'),
                'mail_from' => config('mail.from.address'),
            ],
        ]);
    }

    /**
     * Queue a test mail to the signed-in administrator through the mailer of
     * .env (docs/adr/0044), also while notifications are off.
     */
    public function test(Request $request, #[CurrentUser] User $user, AdminNotifier $notifier): RedirectResponse
    {
        $notifier->test($user);

        AuditLog::record('notifications.test_sent', $user, null, ['email' => $user->email], $request->ip());

        $this->toast('success', 'Test email queued to :email.', ['email' => $user->email]);

        return back();
    }

    public function update(PanelSettingsRequest $request, string $category): RedirectResponse
    {
        $changes = PanelSettings::save($request->settings());

        if ($changes !== []) {
            AuditLog::record('settings.updated', $request->user(), null, ['changes' => $changes], $request->ip());
        }

        $this->toast('success', 'Settings saved.');

        return back();
    }
}
