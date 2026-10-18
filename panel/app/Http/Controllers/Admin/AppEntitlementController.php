<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppEntitlements;
use App\Domain\Apps\AppNotEligible;
use App\Enums\AppEntitlementSource;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\AppEntitlement;
use App\Models\AppRecipe;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administrators grant and revoke rights to paid apps on a server
 * (docs/adr/0019), e.g. for a purchase the billing system did not report.
 */
class AppEntitlementController extends Controller
{
    public function __construct(private readonly AppEntitlements $entitlements) {}

    public function store(Request $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', Rule::exists('app_recipes', 'slug')],
            'reference' => ['nullable', 'string', 'max:191'],
        ]);

        if ($server->status === ServerStatus::Terminated) {
            throw ValidationException::withMessages(['app' => __('This action is not possible while the server is :status.', ['status' => __('Terminated')])]);
        }

        try {
            $this->entitlements->grant($server, AppRecipe::query()->with('latestVersion')->where('slug', $data['app'])->firstOrFail(), AppEntitlementSource::Admin, $admin, $data['reference'] ?? null, $request->ip());
        } catch (AppNotEligible $e) {
            throw ValidationException::withMessages(['app' => $e->translatedMessage()]);
        }

        $this->toast('success', 'The app can now be installed on this server.');

        return back();
    }

    public function destroy(Request $request, AppEntitlement $appEntitlement, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($appEntitlement->revoked_at !== null) {
            return back();
        }

        $this->entitlements->revoke($appEntitlement->server, $appEntitlement->recipe, $admin, $request->ip());
        $this->toast('success', 'The right to the app was revoked. An installed copy stays on the server.');

        return back();
    }
}
