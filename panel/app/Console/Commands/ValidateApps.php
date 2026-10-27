<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\AppPackages;
use App\Domain\Apps\PackageDirectory;
use App\Domain\Apps\RecipeLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

#[Signature('apps:validate {--path= : The recipes repository to check}')]
#[Description('Validate the .proxapp packages of a recipes repository, <slug>/<slug>.proxapp each (docs/adr/0042)')]
class ValidateApps extends Command
{
    public function handle(AppPackages $packages): int
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->components->error('Give the recipes repository with --path.');

            return self::FAILURE;
        }

        $directory = new PackageDirectory(RecipeLoader::resolve($path));

        if ($directory->slugs() === []) {
            $this->components->error("No apps found in {$directory->directory}.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($directory->slugs() as $slug) {
            $problems = $directory->problems($slug);

            if (is_file($directory->path($slug))) {
                try {
                    $package = $packages->read($directory->path($slug));

                    if ($package['slug'] !== $slug) {
                        $problems[] = "The package's recipe.yaml has the slug {$package['slug']}; the folder must be named after it.";
                    }
                } catch (ValidationException $e) {
                    array_push($problems, ...array_values(array_map('strval', Arr::flatten($e->errors()))));
                }
            }

            if ($problems === []) {
                $this->components->info("{$slug}: valid.");

                continue;
            }

            $failed = true;
            $this->components->error("{$slug}:");
            $this->components->bulletList($problems);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
