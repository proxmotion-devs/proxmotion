<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Resolve the locale from the user, the session, then the browser.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $sessionLocale = $request->session()->get('locale');

        $candidates = [
            $user instanceof User ? $user->locale : null,
            is_string($sessionLocale) ? $sessionLocale : null,
            $request->getPreferredLanguage(Locales::SUPPORTED),
        ];

        foreach ($candidates as $locale) {
            if (Locales::isSupported($locale)) {
                App::setLocale($locale);

                break;
            }
        }

        return $next($request);
    }
}
