<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\Catalog\RecipeSource;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:validate {--path= : The recipes directory to check (default PROXMOTION_RECIPES_PATH)}')]
#[Description('Validate the app recipes of a recipes directory against the recipe schema (docs/adr/0015, 0041)')]
class ValidateApps extends Command
{
    public function handle(): int
    {
        $path = $this->recipesPath();

        if ($path === null) {
            return self::FAILURE;
        }

        $loader = new RecipeLoader($path);

        if ($loader->slugs() === []) {
            $this->components->error("No recipes found in {$path}.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($loader->slugs() as $slug) {
            try {
                $loader->load($slug);
                $this->components->info("{$slug}: valid.");
            } catch (RecipeInvalid $e) {
                $failed = true;
                $this->components->error("{$slug}:");
                $this->components->bulletList($e->errors);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The recipes directory from --path, else PROXMOTION_RECIPES_PATH; null (with a message) when neither is set.
     */
    private function recipesPath(): ?string
    {
        $path = $this->option('path') ?? config('proxmotion.recipes.path');

        if (! is_string($path) || $path === '') {
            $this->components->error('Give the recipes directory with --path, or set PROXMOTION_RECIPES_PATH.');

            return null;
        }

        return RecipeSource::directory($path);
    }
}
