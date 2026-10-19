<?php

namespace App\Providers;

use App\Domain\Infrastructure\DistributionCatalog;
use App\Models\ApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read once per request (or queued job) and forgotten when changed.
        $this->app->scoped(DistributionCatalog::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Billing API (docs/adr/0008): per token, set by AuthenticateApiToken.
        RateLimiter::for('api', function (Request $request): Limit {
            $token = $request->attributes->get('api_token');

            return Limit::perMinute(120)->by($token instanceof ApiToken ? 'token:'.$token->id : 'ip:'.$request->ip());
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
