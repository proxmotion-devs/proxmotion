<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\AppPackages;
use App\Domain\Apps\Recipe;
use App\Domain\Apps\RecipeArchive;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

#[Signature('apps:pack
    {--path= : The recipes directory}
    {--check : Write nothing; fail when a package is missing or differs from its recipe}')]
#[Description('Build a .proxapp package of every recipe into <path>/packages (docs/adr/0042)')]
class PackApps extends Command
{
    public function handle(AppPackages $packages, RecipeArchive $archive): int
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $this->components->error('Give the recipes directory with --path.');

            return self::FAILURE;
        }

        $root = RecipeLoader::resolve($path);
        $loader = new RecipeLoader($root);
        $output = $root.'/'.RecipeLoader::PACKAGES;
        $extension = '.'.AppPackages::EXTENSION;
        $check = (bool) $this->option('check');
        $slugs = $loader->slugs();
        $problems = [];

        foreach ($slugs as $slug) {
            $file = $output.'/'.$slug.$extension;

            try {
                $bytes = $packages->pack($root, $slug);
            } catch (RecipeInvalid $e) {
                $problems[] = "{$slug}: the recipe is invalid (php artisan apps:validate).";

                continue;
            }

            if ($check) {
                if (! is_file($file)) {
                    $problems[] = "packages/{$slug}{$extension} is missing.";
                } elseif (! $this->matches($loader, $archive, $slug, $file)) {
                    $problems[] = "packages/{$slug}{$extension} differs from its recipe.";
                }

                continue;
            }

            File::ensureDirectoryExists($output);
            file_put_contents($file, $bytes);
            $this->components->info("packages/{$slug}{$extension}");
        }

        foreach (glob($output.'/*'.$extension) ?: [] as $file) {
            $slug = basename($file, $extension);

            if (in_array($slug, $slugs, true)) {
                continue;
            }

            if ($check) {
                $problems[] = "packages/{$slug}{$extension} has no recipe.";
            } else {
                unlink($file);
                $this->components->info("Removed packages/{$slug}{$extension}.");
            }
        }

        if ($problems !== []) {
            $this->components->error($check ? 'The packages do not match the recipes; run php artisan apps:pack:' : 'Some recipes were not packed:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        if ($check) {
            $this->components->info('Every package matches its recipe.');
        }

        return self::SUCCESS;
    }

    /**
     * Whether a package holds the same version as the recipe (recipe.yaml and the files it uses; a README is not part of a version).
     */
    private function matches(RecipeLoader $loader, RecipeArchive $archive, string $slug, string $file): bool
    {
        try {
            ['recipe' => $recipe, 'files' => $files] = $loader->load($slug);
            ['definition' => $definition, 'files' => $packed] = $archive->read($file);
        } catch (RecipeInvalid|ValidationException) {
            return false;
        }

        return RecipeSynchronizer::checksum($recipe, $files) === RecipeSynchronizer::checksum(Recipe::fromArray($definition), $packed);
    }
}
