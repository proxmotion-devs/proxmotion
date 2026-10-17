<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:sync')]
#[Description('Copy the bundled app recipes into the database')]
class SyncApps extends Command
{
    public function handle(RecipeSynchronizer $synchronizer): int
    {
        try {
            $result = $synchronizer->sync();
        } catch (RecipeInvalid $e) {
            $this->components->error("{$e->slug} is invalid; nothing was synced:");
            $this->components->bulletList($e->errors);

            return self::FAILURE;
        }

        foreach (['created' => 'Added', 'updated' => 'Updated', 'deactivated' => 'Deactivated', 'skipped' => 'Skipped (a custom app has the slug)'] as $key => $label) {
            if ($result[$key] !== []) {
                $this->components->info($label.': '.implode(', ', $result[$key]));
            }
        }

        if (array_merge(...array_values($result)) === []) {
            $this->components->info('All recipes are up to date.');
        }

        return self::SUCCESS;
    }
}
