<?php

namespace Tests\Feature\Installation;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ReplacesArtisanCommands;
use Tests\TestCase;

/**
 * proxmotion:install (docs/adr/0052 §3): requirements, application key,
 * migrations and the first administrator; safe to run again.
 */
class InstallPanelTest extends TestCase
{
    use RefreshDatabase, ReplacesArtisanCommands;

    private ?string $environment = null;

    protected function tearDown(): void
    {
        if ($this->environment !== null) {
            File::deleteDirectory($this->environment);
        }

        parent::tearDown();
    }

    /**
     * Points the application at a temporary directory, so key:generate never
     * touches the real .env.
     */
    private function useEnvironment(?string $contents): void
    {
        $this->environment = sys_get_temp_dir().'/proxmotion-install-'.uniqid();
        File::ensureDirectoryExists($this->environment);

        if ($contents !== null) {
            file_put_contents($this->environment.'/.env', $contents);
        }

        $this->app->useEnvironmentPath($this->environment);
    }

    public function test_the_first_administrator_gets_a_generated_password_without_questions()
    {
        $this->artisan('proxmotion:install', [
            '--admin-name' => 'Ada Admin',
            '--admin-email' => 'ada@example.com',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Password of ada@example.com')
            ->expectsOutputToContain('Proxmotion is installed')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('Ada Admin', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        $log = AuditLog::query()->sole();
        $this->assertSame('user.created', $log->action);
        $this->assertNull($log->user_id);
        $this->assertSame(['email' => 'ada@example.com', 'source' => 'install'], $log->properties);
    }

    public function test_the_first_administrator_is_asked_for_when_interactive()
    {
        $this->artisan('proxmotion:install')
            ->expectsQuestion('Name of the first administrator', 'Ada Admin')
            ->expectsQuestion('Email address of the first administrator', 'ada@example.com')
            ->expectsQuestion('Password (leave empty to generate one)', 'a-long-Passw0rd')
            ->doesntExpectOutputToContain('Password of')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('a-long-Passw0rd', User::query()->sole()->password));
    }

    public function test_no_administrator_is_created_when_one_exists()
    {
        User::factory()->create();

        $this->artisan('proxmotion:install', ['--no-interaction' => true])
            ->expectsOutputToContain('Administrators exist already')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_without_questions_the_administrator_needs_name_and_email()
    {
        $this->artisan('proxmotion:install', ['--admin-email' => 'ada@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('--admin-name and --admin-email')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_an_invalid_administrator_is_refused()
    {
        $this->artisan('proxmotion:install', [
            '--admin-name' => 'Ada Admin',
            '--admin-email' => 'not-an-address',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('The administrator was not created')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_missing_application_key_is_written_to_the_env_file()
    {
        $this->useEnvironment("APP_NAME=Proxmotion\nAPP_KEY=\n");
        config(['app.key' => '']);
        User::factory()->create();

        $this->artisan('proxmotion:install', ['--no-interaction' => true])->assertSuccessful();

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S+$/m', (string) file_get_contents($this->environment.'/.env'));
        $this->assertNotEmpty(config('app.key'));
    }

    public function test_a_key_in_the_env_file_is_never_overwritten()
    {
        // An empty APP_KEY in the environment wins over the file's key.
        $this->useEnvironment("APP_KEY=base64:b2xkLWtleQ==\n");
        config(['app.key' => '']);

        $this->artisan('proxmotion:install', ['--admin-name' => 'Ada Admin', '--admin-email' => 'ada@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Fix what is missing')
            ->assertFailed();

        $this->assertSame("APP_KEY=base64:b2xkLWtleQ==\n", file_get_contents($this->environment.'/.env'));
    }

    public function test_an_empty_answer_says_what_the_administrator_needs()
    {
        $this->artisan('proxmotion:install')
            ->expectsQuestion('Name of the first administrator', '')
            ->expectsQuestion('Email address of the first administrator', 'ada@example.com')
            ->expectsOutputToContain('needs a name and an email address')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_in_production_a_generated_password_skips_the_breach_lookup_and_the_caches_are_built()
    {
        $this->app->detectEnvironment(fn () => 'production');
        Http::fake();
        $optimized = false;
        $this->replaceCommand('optimize', function () use (&$optimized) {
            $optimized = true;

            return 0;
        });

        $this->artisan('proxmotion:install', ['--admin-name' => 'Ada Admin', '--admin-email' => 'ada@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Password of ada@example.com')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertTrue($optimized);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_failing_cache_build_fails_the_install()
    {
        $this->app->detectEnvironment(fn () => 'production');
        User::factory()->create();
        $this->replaceCommand('optimize', fn () => throw new \LogicException('Your configuration files are not serializable.'));

        $this->artisan('proxmotion:install', ['--no-interaction' => true])
            ->expectsOutputToContain('not serializable')
            ->assertFailed();
    }

    public function test_the_lock_is_released_afterwards()
    {
        User::factory()->create();

        $this->artisan('proxmotion:install', ['--no-interaction' => true])->assertSuccessful();

        $this->assertTrue(Cache::lock('proxmotion:prepare', 1)->get());
    }

    public function test_a_database_cache_without_its_lock_table_runs_without_the_lock()
    {
        // A fresh database: the migrations create the lock table only now.
        config(['cache.default' => 'database', 'cache.stores.database.lock_table' => 'missing_locks']);
        User::factory()->create();

        $this->artisan('proxmotion:install', ['--no-interaction' => true])->assertSuccessful();
    }

    public function test_without_an_env_file_a_missing_application_key_stops_the_install()
    {
        $this->useEnvironment(null);
        config(['app.key' => '']);

        $this->artisan('proxmotion:install', ['--admin-name' => 'Ada Admin', '--admin-email' => 'ada@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('key:generate --show')
            ->expectsOutputToContain('Fix what is missing')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->environment.'/.env');
        $this->assertSame(0, User::query()->count());
    }
}
