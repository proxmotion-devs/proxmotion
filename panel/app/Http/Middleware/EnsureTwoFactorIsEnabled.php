<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsEnabled
{
    /**
     * Send users without confirmed TOTP two-factor authentication to the security settings.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            config('proxmotion.require_admin_two_factor')
            && Features::canManageTwoFactorAuthentication()
            && $user instanceof User
            && ! $user->hasEnabledTwoFactorAuthentication()
        ) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Administrators must enable two-factor authentication.'),
            ]);

            return to_route('security.edit');
        }

        return $next($request);
    }
}
