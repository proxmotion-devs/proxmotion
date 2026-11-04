<?php

use App\Domain\Settings\Branding;
use App\Http\Controllers\BrandingFileController;
use App\Http\ErrorPages;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateConsoleProxy;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\ScopeToBillingSystem;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware([AuthenticateApiToken::class, 'throttle:api', SubstituteBindings::class, ScopeToBillingSystem::class])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));

            // Uploaded logos and favicon (docs/adr/0053), also on the sign-in pages and the customer
            // console. Without the web group: a publicly cached image must never carry a session or
            // XSRF cookie, and needs none of the page middleware.
            Route::get('branding/{file}', BrandingFileController::class)
                ->where('file', Branding::FILE_PATTERN)
                ->name('branding.image');

            Route::middleware(['throttle:120,1', AuthenticateConsoleProxy::class])
                ->prefix('internal')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));
        },
    )
    // Private channels are for administrators only, behind the same checks as admin pages (docs/adr/0036).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'verified', '2fa']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Forwarded headers only from TRUSTED_PROXIES (docs/adr/0054).
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        // Guest scripts (admin OS template page) are stored exactly as typed.
        $middleware->trimStrings(except: ['script']);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Billing API: token, then its rate limit, then its scope — all before route
        // model binding, so a bad token or scope never learns whether a server exists.
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiToken::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireApiScope::class);

        $middleware->alias([
            '2fa' => EnsureTwoFactorIsEnabled::class,
            'api.scope' => RequireApiScope::class,
            'api.idempotent' => HandleIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // The panel's own error page for browser requests (403, 404, 500, 503).
        $exceptions->respond(ErrorPages::respond(...));
    })->create();
