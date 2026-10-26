<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\Catalog\RecipeSource;
use App\Domain\Apps\RecipeSkeleton;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:make {slug : lower-case letters, digits and dashes, e.g. uptime-kuma} {--name= : display name} {--platform=linux : linux or windows (docs/adr/0029)} {--path= : the recipes directory to create it in (default PROXMOTION_RECIPES_PATH)}')]
#[Description('Create a format 2 app recipe skeleton in a recipes directory (docs/adr/0016, 0041)')]
class MakeApp extends Command
{
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $slug = is_string($slug) ? $slug : '';

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug) !== 1) {
            $this->components->error('The slug must have 3 to 50 lower-case letters, digits and dashes, and start and end with a letter or digit.');

            return self::FAILURE;
        }

        $platform = $this->option('platform');

        if (! in_array($platform, RecipeSkeleton::PLATFORMS, true)) {
            $this->components->error('The platform must be linux or windows.');

            return self::FAILURE;
        }

        $root = $this->option('path') ?? config('proxmotion.recipes.path');

        if (! is_string($root) || $root === '') {
            $this->components->error('Give the recipes directory with --path, or set PROXMOTION_RECIPES_PATH.');

            return self::FAILURE;
        }

        $root = RecipeSource::directory($root);
        $directory = $root.'/'.$slug;

        if (file_exists($directory)) {
            $this->components->error("{$directory} already exists.");

            return self::FAILURE;
        }

        $name = $this->option('name');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : ucwords(str_replace('-', ' ', $slug));

        mkdir($directory, 0755, true);

        foreach (RecipeSkeleton::files($slug, $name, $platform) as $file => $content) {
            file_put_contents($directory.'/'.$file, $content);
        }

        $this->components->info("Created {$directory}.");
        $this->components->bulletList(array_values(array_filter([
            'Edit recipe.yaml (requirements, inputs, outputs) and the scripts.',
            'php artisan apps:validate --path='.$root,
            $platform === 'linux' ? 'shellcheck '.$directory.'/*.sh' : null,
            'Commit it to the recipes repository; an administrator downloads it in the App Store.',
        ])));

        return self::SUCCESS;
    }
}
