<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Support\Broadcasting\ReverbFallback;
use App\Support\Database\SqliteConnector;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
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
        // `php artisan serve` hands its PHP server only the variables listed here. Without TEMP and TMP,
        // PHP on Windows uses C:\Windows as its temporary directory, where Symfony Process cannot
        // write a command's output, so the App Store's refresh could not run git (docs/adr/0041).
        ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP', 'TMPDIR']));

        // IMMEDIATE SQLite transactions on PHP 8.3 too (config/database.php).
        $this->app->bind('db.connector.sqlite', SqliteConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Before the channels are registered (bootstrap/app.php, after boot): an unconfigured Reverb must not stop the panel.
        ReverbFallback::apply(config());

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
