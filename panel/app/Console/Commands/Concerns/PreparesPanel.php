<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Domain\Installation\Requirements;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * The steps proxmotion:install and proxmotion:upgrade share (docs/adr/0052).
 *
 * @mixin Command
 */
trait PreparesPanel
{
    /** Held by both commands, so containers starting together run them one after the other. */
    private const string LOCK = 'proxmotion:prepare';

    /** How long a second run waits for the first. */
    private const int LOCK_WAIT_SECONDS = 900;

    /** How long the lock lives at most: long enough for slow migrations. */
    private const int LOCK_SECONDS = 3600;

    /**
     * Runs the command's steps under the lock, with the current configuration.
     *
     * @param  list<string>  $arguments  this run's options, to run it again
     * @param  callable(): int  $steps
     */
    private function prepare(array $arguments, callable $steps): int
    {
        if ($this->laravel->configurationIsCached()) {
            return $this->runAgainWithoutCachedConfiguration($arguments);
        }

        $lock = $this->canLock() ? Cache::lock(self::LOCK, self::LOCK_SECONDS) : null;

        try {
            $lock?->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $this->components->error('Another proxmotion:install or proxmotion:upgrade is still running.');

            return self::FAILURE;
        }

        try {
            return $steps();
        } finally {
            $lock?->release();
        }
    }

    /**
     * A fresh database whose cache is the database store has no lock table
     * yet: nothing to lock against. Parallel first installs need a Redis
     * cache (docs/adr/0052 §7).
     */
    private function canLock(): bool
    {
        $store = config('cache.stores.'.config()->string('cache.default'));

        if (! is_array($store) || ($store['driver'] ?? null) !== 'database') {
            return true;
        }

        // As Laravel's CacheManager reads them.
        $connection = $store['lock_connection'] ?? $store['connection'] ?? null;
        $table = $store['lock_table'] ?? null;

        return Schema::connection(is_string($connection) ? $connection : null)
            ->hasTable(is_string($table) && $table !== '' ? $table : 'cache_locks');
    }

    /**
     * A configuration cached by the previous version would be what this run
     * checks and migrates with: removed, and the command run again in a fresh
     * process that reads the configuration files (docs/adr/0052 §6).
     *
     * @param  list<string>  $arguments
     */
    private function runAgainWithoutCachedConfiguration(array $arguments): int
    {
        $this->callSilently('config:clear');

        // configurationIsCached() stays true for this process: the file counts.
        if (is_file(app()->getCachedConfigPath())) {
            $this->components->error('The cached configuration could not be removed: run php artisan config:clear first.');

            return self::FAILURE;
        }

        $this->components->info('Removed the cached configuration; running again with the current one.');

        $interactive = $this->input->isInteractive();
        $command = [
            PHP_BINARY, 'artisan', (string) $this->getName(), ...$arguments,
            ...($interactive ? [] : ['--no-interaction']),
            ...($this->output->isDecorated() ? ['--ansi'] : []),
        ];

        $result = Process::forever()
            ->path(base_path())
            ->tty($interactive && SymfonyProcess::isTtySupported())
            ->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * Lists every check; false when a required one is missing.
     */
    private function checkRequirements(Requirements $requirements): bool
    {
        $results = $requirements->check();

        foreach ($results as $result) {
            $label = $result->label.($result->note === null ? '' : ' <fg=gray>'.OutputFormatter::escape($result->note).'</>');
            $status = match (true) {
                $result->passed => '<fg=green;options=bold>OK</>',
                $result->required => '<fg=red;options=bold>MISSING</>',
                default => '<fg=yellow;options=bold>WARNING</>',
            };

            $this->components->twoColumnDetail($label, $status);
        }

        if (Requirements::missing($results)) {
            $this->components->error('Fix what is missing and run the command again.');

            return false;
        }

        return true;
    }

    private function migrate(): bool
    {
        return $this->attempt(fn () => $this->call('migrate', ['--force' => true]) === self::SUCCESS);
    }

    private function cacheForProduction(): bool
    {
        if (! app()->isProduction()) {
            return true;
        }

        if ($this->callSilently('optimize') !== self::SUCCESS) {
            $this->components->error('Caching the configuration, events, routes and views failed: php artisan optimize says why.');

            return false;
        }

        $this->components->info('Configuration, events, routes and views cached.');

        return true;
    }

    /**
     * Runs one step; an exception is reported and printed instead of ending
     * the command half way without saying what state the panel is in.
     *
     * @param  callable(): bool  $step
     */
    private function attempt(callable $step): bool
    {
        try {
            return $step();
        } catch (Throwable $e) {
            report($e);
            $this->components->error($e->getMessage());

            return false;
        }
    }
}
