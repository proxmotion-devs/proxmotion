<?php

declare(strict_types=1);

namespace App\Domain\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * What proxmotion:install and proxmotion:upgrade check before they change
 * anything (docs/adr/0052 §2): PHP and its extensions, writable directories,
 * the application key, the database and Redis; then settings that work but
 * should not stay as they are, which only warn.
 */
class Requirements
{
    public const string MIN_PHP = '8.3.0';

    /** Besides the database driver; intl for Number, zip for .proxapp packages. */
    public const array EXTENSIONS = [
        'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip',
    ];

    /**
     * @return list<Requirement>
     */
    public function check(): array
    {
        return [
            ...$this->php(),
            ...$this->directories(),
            new Requirement('Application key (APP_KEY)', filled(config('app.key')), note: 'php artisan key:generate --show prints one'),
            $this->database(),
            ...$this->redis(),
            ...$this->advice(),
        ];
    }

    /**
     * @param  list<Requirement>  $requirements
     */
    public static function missing(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement->required && ! $requirement->passed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Requirement>
     */
    private function php(): array
    {
        $results = [new Requirement('PHP '.self::MIN_PHP.' or newer', version_compare(PHP_VERSION, self::MIN_PHP, '>='), note: PHP_VERSION)];

        $extensions = self::EXTENSIONS;

        if ($driver = $this->databaseExtension()) {
            $extensions[] = $driver;
        }

        if ($this->usesRedis() && config('database.redis.client') === 'phpredis') {
            $extensions[] = 'redis';
        }

        foreach ($extensions as $extension) {
            $results[] = new Requirement("PHP extension {$extension}", extension_loaded($extension));
        }

        $results[] = new Requirement('PHP extension pcntl', extension_loaded('pcntl'), required: false, note: 'lets queue workers stop jobs at their timeout');

        return $results;
    }

    /**
     * @return list<Requirement>
     */
    private function directories(): array
    {
        return array_map(
            fn (string $directory) => new Requirement("Writable {$directory}", is_writable(base_path($directory))),
            ['storage', 'bootstrap/cache'],
        );
    }

    private function database(): Requirement
    {
        $connection = config()->string('database.default');

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return new Requirement("Database connection ({$connection})", false, note: $e->getMessage());
        }

        return new Requirement("Database connection ({$connection})", true);
    }

    /**
     * @return list<Requirement>
     */
    private function redis(): array
    {
        if (! $this->usesRedis()) {
            return [];
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $e) {
            return [new Requirement('Redis connection', false, note: $e->getMessage())];
        }

        return [new Requirement('Redis connection', true)];
    }

    /**
     * Settings that work but should not stay as they are: they only warn.
     *
     * @return list<Requirement>
     */
    private function advice(): array
    {
        $results = [
            new Requirement('Console proxy secret (CONSOLE_PROXY_SECRET)', filled(config('proxmotion.console.proxy_secret')), required: false, note: 'the browser console does not work without it'),
        ];

        if (! app()->isProduction()) {
            return $results;
        }

        return [
            ...$results,
            new Requirement('Debug mode off (APP_DEBUG)', ! config('app.debug'), required: false, note: 'error pages would show code and settings'),
            new Requirement('HTTPS address (APP_URL)', str_starts_with(config()->string('app.url'), 'https://'), required: false),
            new Requirement('Mail delivery (MAIL_MAILER)', ! in_array(config('mail.default'), ['log', 'array'], true), required: false, note: 'no notifications or password reset links are sent'),
            new Requirement('Queue worker (QUEUE_CONNECTION)', $this->queueDriver() !== 'sync', required: false, note: 'sync runs every Proxmox task inside the web request'),
        ];
    }

    private function databaseExtension(): ?string
    {
        return match (config('database.connections.'.config()->string('database.default').'.driver')) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            'pgsql' => 'pdo_pgsql',
            default => null,
        };
    }

    private function usesRedis(): bool
    {
        $cache = config()->string('cache.default');

        return $this->queueDriver() === 'redis'
            || $this->cacheDriver($cache) === 'redis'
            // The maintenance state shared by several machines (ADR 0052 §5).
            || (config('app.maintenance.driver') === 'cache' && $this->cacheDriver(config('app.maintenance.store') ?: $cache) === 'redis')
            || config('session.driver') === 'redis';
    }

    private function cacheDriver(mixed $store): mixed
    {
        return is_string($store) ? config("cache.stores.{$store}.driver") : null;
    }

    private function queueDriver(): mixed
    {
        return config('queue.connections.'.config()->string('queue.default').'.driver');
    }
}
