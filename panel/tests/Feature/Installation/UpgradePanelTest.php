<?php

namespace Tests\Feature\Installation;

use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LogicException;
use Tests\Concerns\ReplacesArtisanCommands;
use Tests\TestCase;

/**
 * proxmotion:upgrade (docs/adr/0052 §4): maintenance mode around the
 * migrations, workers told to restart before the panel opens again.
 */
class UpgradePanelTest extends TestCase
{
    use RefreshDatabase, ReplacesArtisanCommands;

    private ?string $migrations = null;

    private ?string $cachedConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The cache driver keeps the maintenance state in the test's array
        // store instead of storage/framework/down.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    }

    protected function tearDown(): void
    {
        if ($this->migrations !== null) {
            File::deleteDirectory($this->migrations);
        }

        if ($this->cachedConfig !== null) {
            File::delete(base_path($this->cachedConfig));
            unset($_SERVER['APP_CONFIG_CACHE'], $_ENV['APP_CONFIG_CACHE']);
        }

        parent::tearDown();
    }

    private function migrationsRan(): \Closure
    {
        $ran = false;
        Event::listen([MigrationsStarted::class, NoPendingMigrations::class], function () use (&$ran) {
            $ran = true;
        });

        return function () use (&$ran) {
            return $ran;
        };
    }

    public function test_a_failed_switch_to_maintenance_mode_changes_nothing()
    {
        $this->replaceCommand('down {--retry=}', fn () => 1);
        $migrationsRan = $this->migrationsRan();

        $this->artisan('proxmotion:upgrade')
            ->expectsOutputToContain('could not go into maintenance mode')
            ->assertFailed();

        $this->assertFalse($migrationsRan());
        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_in_production_the_caches_are_built_before_the_panel_opens()
    {
        $this->app->detectEnvironment(fn () => 'production');
        $downWhileCaching = null;
        $this->replaceCommand('optimize', function () use (&$downWhileCaching) {
            $downWhileCaching = app()->isDownForMaintenance();

            return 0;
        });

        $this->artisan('proxmotion:upgrade')->assertSuccessful();

        $this->assertTrue($downWhileCaching);
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_a_failing_cache_build_leaves_the_panel_in_maintenance_mode()
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->replaceCommand('optimize', fn () => throw new LogicException('Your configuration files are not serializable.'));

        $this->artisan('proxmotion:upgrade')
            ->expectsOutputToContain('not serializable')
            ->expectsOutputToContain('stays in maintenance mode')
            ->assertFailed();

        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_a_failed_restart_signal_leaves_the_panel_in_maintenance_mode()
    {
        $this->replaceCommand('queue:restart', fn () => 1);

        $this->artisan('proxmotion:upgrade')
            ->expectsOutputToContain('queue:restart failed')
            ->assertFailed();

        $this->assertTrue(app()->isDownForMaintenance());
    }

    public function test_a_cached_configuration_is_removed_and_the_upgrade_runs_again_in_a_fresh_process()
    {
        $this->cachedConfig = 'storage/framework/testing-config-'.uniqid().'.php';
        file_put_contents(base_path($this->cachedConfig), '<?php return [];');
        $_SERVER['APP_CONFIG_CACHE'] = $_ENV['APP_CONFIG_CACHE'] = $this->cachedConfig;
        // As if this process had loaded its configuration from that file.
        $this->app->instance('config_loaded_from_cache', true);
        Process::fake();
        $migrationsRan = $this->migrationsRan();

        $this->artisan('proxmotion:upgrade')->assertSuccessful();

        $this->assertFileDoesNotExist(base_path($this->cachedConfig));
        Process::assertRan(fn ($process) => in_array('proxmotion:upgrade', (array) $process->command, true)
            && in_array('--no-interaction', (array) $process->command, true) === false);
        $this->assertFalse($migrationsRan());
        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_the_panel_is_closed_while_migrating_and_reopened_after_the_restart_signals()
    {
        $downWhileMigrating = null;
        Event::listen([MigrationsStarted::class, NoPendingMigrations::class], function () use (&$downWhileMigrating) {
            $downWhileMigrating = app()->isDownForMaintenance();
        });

        $this->artisan('proxmotion:upgrade')
            ->expectsOutputToContain('Proxmotion is upgraded')
            ->assertSuccessful();

        $this->assertTrue($downWhileMigrating);
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertNotNull(Cache::get('illuminate:queue:restart'));
        $this->assertNotNull(Cache::get('laravel:reverb:restart'));
    }

    public function test_a_maintenance_mode_set_before_the_upgrade_stays()
    {
        $this->artisan('down')->assertSuccessful();

        $this->artisan('proxmotion:upgrade')->assertSuccessful();

        $this->assertTrue(app()->isDownForMaintenance());
    }

    public function test_a_failed_migration_leaves_the_panel_in_maintenance_mode()
    {
        $this->migrations = sys_get_temp_dir().'/proxmotion-upgrade-'.uniqid();
        File::ensureDirectoryExists($this->migrations);
        file_put_contents($this->migrations.'/2099_01_01_000000_fails.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;

            return new class extends Migration
            {
                public function up(): void
                {
                    throw new RuntimeException('The migration failed.');
                }
            };
            PHP);
        $this->app->make('migrator')->path($this->migrations);

        $this->artisan('proxmotion:upgrade')
            ->expectsOutputToContain('stays in maintenance mode')
            ->assertFailed();

        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertNull(Cache::get('illuminate:queue:restart'));
    }

    public function test_missing_requirements_stop_the_upgrade_before_maintenance()
    {
        config(['app.key' => '']);

        $this->artisan('proxmotion:upgrade')->assertFailed();

        $this->assertFalse(app()->isDownForMaintenance());
    }
}
