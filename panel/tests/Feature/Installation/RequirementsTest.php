<?php

namespace Tests\Feature\Installation;

use App\Domain\Installation\Requirement;
use App\Domain\Installation\Requirements;
use Tests\TestCase;

/**
 * What proxmotion:install and proxmotion:upgrade check first (docs/adr/0052 §2).
 */
class RequirementsTest extends TestCase
{
    /**
     * @return array<string, Requirement>
     */
    private function check(): array
    {
        $results = [];

        foreach (app(Requirements::class)->check() as $requirement) {
            $results[$requirement->label] = $requirement;
        }

        return $results;
    }

    public function test_the_test_environment_has_everything_required()
    {
        $results = $this->check();

        $this->assertFalse(Requirements::missing(array_values($results)));
        $this->assertTrue($results['Database connection (sqlite)']->passed);
        $this->assertArrayHasKey('PHP extension pdo_sqlite', $results);
        $this->assertArrayNotHasKey('Redis connection', $results);
        $this->assertArrayNotHasKey('Debug mode off (APP_DEBUG)', $results);
    }

    public function test_a_missing_key_is_required_and_the_console_secret_only_warns()
    {
        config(['app.key' => '', 'proxmotion.console.proxy_secret' => null]);

        $results = $this->check();

        $this->assertFalse($results['Application key (APP_KEY)']->passed);
        $this->assertFalse($results['Console proxy secret (CONSOLE_PROXY_SECRET)']->passed);
        $this->assertFalse($results['Console proxy secret (CONSOLE_PROXY_SECRET)']->required);
        $this->assertTrue(Requirements::missing(array_values($results)));
    }

    public function test_redis_is_checked_when_the_queue_uses_it()
    {
        config([
            'queue.default' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
        ]);

        $results = $this->check();

        $this->assertFalse($results['Redis connection']->passed);
        $this->assertTrue(Requirements::missing(array_values($results)));
    }

    public function test_redis_is_checked_when_the_maintenance_state_lives_there()
    {
        config([
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
        ]);

        $results = $this->check();

        $this->assertArrayHasKey('Redis connection', $results);
        $this->assertFalse($results['Redis connection']->passed);
    }

    public function test_production_settings_that_should_change_only_warn()
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => true,
            'app.url' => 'http://panel.example.com',
            'mail.default' => 'log',
            'queue.default' => 'sync',
        ]);

        $results = $this->check();

        foreach (['Debug mode off (APP_DEBUG)', 'HTTPS address (APP_URL)', 'Mail delivery (MAIL_MAILER)', 'Queue worker (QUEUE_CONNECTION)'] as $label) {
            $this->assertFalse($results[$label]->passed, $label);
            $this->assertFalse($results[$label]->required, $label);
        }

        $this->assertFalse(Requirements::missing(array_values($results)));
    }
}
