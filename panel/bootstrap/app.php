<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateConsoleProxy;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleIdempotency;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\ScopeToBillingSystem;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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

            Route::middleware(['throttle:120,1', AuthenticateConsoleProxy::class])
                ->prefix('internal')
                ->name('internal.')
                ->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

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
    })->create();
