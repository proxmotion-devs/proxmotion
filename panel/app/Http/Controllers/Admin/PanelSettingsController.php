<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\PanelSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PanelSettingsRequest;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel settings (docs/adr/0038): what used to need an .env change and a
 * restart. Security and deployment settings stay in .env and are only shown.
 */
class PanelSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings/Edit', [
            'settings' => PanelSettings::all(),
            'unsaved' => PanelSettings::unsaved(),
            'environment' => [
                'require_admin_two_factor' => (bool) config('proxmotion.require_admin_two_factor'),
                'console_proxy' => filled(config('proxmotion.console.proxy_secret')),
            ],
        ]);
    }

    public function update(PanelSettingsRequest $request): RedirectResponse
    {
        $changes = PanelSettings::save($request->settings());

        if ($changes !== []) {
            AuditLog::record('settings.updated', $request->user(), null, ['changes' => $changes], $request->ip());
        }

        $this->toast('success', 'Settings saved.');

        return back();
    }
}
