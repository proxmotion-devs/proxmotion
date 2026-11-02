<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;

/**
 * Replaces an Artisan command for one test, e.g. optimize, which would
 * otherwise write caches into bootstrap/cache, or down to make it fail.
 */
trait ReplacesArtisanCommands
{
    /**
     * @param  string  $signature  the command's name and the options it is called with
     * @param  Closure(): int  $handle
     */
    protected function replaceCommand(string $signature, Closure $handle): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->registerCommand(new ClosureCommand($signature, $handle));
    }
}
