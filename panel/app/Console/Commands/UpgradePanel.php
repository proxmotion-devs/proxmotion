<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PreparesPanel;
use App\Domain\Installation\Requirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('proxmotion:upgrade')]
#[Description('Upgrade the panel after its code changed: maintenance mode, migrate, cache, restart workers (docs/adr/0052)')]
class UpgradePanel extends Command
{
    use PreparesPanel;

    /** Restarts that exist only when their package is installed. */
    private const array OPTIONAL_RESTARTS = ['horizon:terminate', 'reverb:restart'];

    private const string STAYS_DOWN = 'The panel stays in maintenance mode. Fix the cause and run proxmotion:upgrade again, or open it as it is with php artisan up.';

    public function handle(Requirements $requirements): int
    {
        return $this->prepare([], fn () => $this->upgrade($requirements));
    }

    private function upgrade(Requirements $requirements): int
    {
        if (! $this->checkRequirements($requirements)) {
            return self::FAILURE;
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

        $this->components->info('Proxmotion is upgraded.');

        return self::SUCCESS;
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
