<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:validate')]
#[Description('Validate the app recipes in panel/recipes against the recipe schema')]
class ValidateApps extends Command
{
    public function handle(RecipeLoader $loader): int
    {
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
}
