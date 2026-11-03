<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PreparesPanel;
use App\Domain\Installation\Requirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

#[Signature('proxmotion:upgrade
    {--when-needed : Only when migrations are pending: otherwise check the requirements and change nothing (the migrate role of the Docker images, docs/adr/0054)}')]
#[Description('Upgrade the panel after its code changed: maintenance mode, migrate, cache, restart workers (docs/adr/0052)')]
class UpgradePanel extends Command
{
    use PreparesPanel;

    /** Restarts that exist only when their package is installed. */
    private const array OPTIONAL_RESTARTS = ['reverb:restart'];

    private const string STAYS_DOWN = 'The panel stays in maintenance mode. Fix the cause and run proxmotion:upgrade again, or open it as it is with php artisan up.';

    public function handle(Requirements $requirements): int
    {
        return $this->prepare($this->option('when-needed') ? ['--when-needed'] : [], fn () => $this->upgrade($requirements));
    }

    private function upgrade(Requirements $requirements): int
    {
        if (! $this->checkRequirements($requirements)) {
            return self::FAILURE;
        }

        // New code in containers comes with new containers: without migrations there is
        // nothing to close the panel for, e.g. after only .env changed (docs/adr/0054).
        if ($this->option('when-needed')) {
            $pending = false;

            if (! $this->attempt(function () use (&$pending): bool {
                $pending = $this->hasPendingMigrations();

                return true;
            })) {
                return self::FAILURE;
            }

            if (! $pending) {
                $this->components->info('No migrations are pending: the panel stays open.');

                return self::SUCCESS;
            }
        }

        // An administrator's own maintenance mode stays after the upgrade.
        $wasDown = $this->laravel->isDownForMaintenance();

        if (! $wasDown && $this->call('down', ['--retry' => 60]) !== self::SUCCESS) {
            $this->components->error('The panel could not go into maintenance mode: nothing was changed.');

            return self::FAILURE;
        }

        // A half-migrated database or stale caches are worse than a closed panel.
        if (! $this->migrate() || ! $this->attempt(fn () => $this->cacheForProduction() && $this->restartWorkers())) {
            $this->components->error(self::STAYS_DOWN);

            return self::FAILURE;
        }

        if (! $wasDown && $this->call('up') !== self::SUCCESS) {
            $this->components->error('The panel is upgraded but could not leave maintenance mode: run php artisan up.');

            return self::FAILURE;
        }

        $this->components->info('Proxmotion is upgraded to '.config('proxmotion.version').'.');

        return self::SUCCESS;
    }

    private function hasPendingMigrations(): bool
    {
        /** @var Migrator $migrator */
        $migrator = $this->laravel->make('migrator');

        if (! $migrator->repositoryExists()) {
            return true;
        }

        $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->laravel->databasePath('migrations')]);

        return array_diff(array_keys($files), $migrator->getRepository()->getRan()) !== [];
    }

    /**
     * Before the panel opens: workers paused by the maintenance mode see the
     * signal, quit and are started on the new code by their supervisor.
     */
    private function restartWorkers(): bool
    {
        $commands = ['queue:restart', ...array_filter(
            self::OPTIONAL_RESTARTS,
            fn (string $command) => (bool) $this->getApplication()?->has($command),
        )];

        foreach ($commands as $command) {
            if ($this->callSilently($command) !== self::SUCCESS) {
                $this->components->error("{$command} failed.");

                return false;
            }
        }

        $this->components->info('Queue workers and Reverb told to restart.');

        return true;
    }
}
