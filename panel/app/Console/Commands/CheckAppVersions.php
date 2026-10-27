<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\PackageDirectory;
use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeVersionGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:check-versions
    {--base-path= : The recipes repository at the base revision to compare with}
    {--path= : The recipes repository to check}')]
#[Description('Fail when a package changed without a higher version (an installed version is never replaced)')]
class CheckAppVersions extends Command
{
    public function handle(RecipeVersionGuard $guard): int
    {
        $base = $this->option('base-path');
        $path = $this->option('path');

        if (! is_string($base) || $base === '' || ! is_string($path) || $path === '') {
            $this->components->error('Give the base revision with --base-path and the recipes repository with --path.');

            return self::FAILURE;
        }

        $problems = $guard->problems(new PackageDirectory(RecipeLoader::resolve($base)), new PackageDirectory(RecipeLoader::resolve($path)));

        if ($problems !== []) {
            $this->components->error('Packages changed without a new version:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $this->components->info('Every changed package has a new version.');

        return self::SUCCESS;
    }
}
