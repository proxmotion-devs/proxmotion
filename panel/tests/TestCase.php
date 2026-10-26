<?php

namespace Tests;

use App\Domain\Apps\Catalog\AppCatalog;
use App\Domain\Apps\Catalog\CatalogRefused;
use App\Domain\Apps\Catalog\RecipeSource;
use App\Models\AppCatalogEntry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Refresh the official recipe source (PROXMOTION_RECIPES_PATH: tests/Fixtures/recipes) and download every app of it,
     * as an administrator does in the App Store. Returns the slugs created, updated, held or refused.
     *
     * @return array{created: list<string>, updated: list<string>, held: list<string>, skipped: list<string>}
     */
    protected function syncRecipes(): array
    {
        $catalog = app(AppCatalog::class);
        $catalog->refresh(RecipeSource::official());
        $result = ['created' => [], 'updated' => [], 'held' => [], 'skipped' => []];

        foreach (AppCatalogEntry::query()->orderBy('slug')->get() as $entry) {
            try {
                $outcome = $catalog->download($entry, null, null);
            } catch (CatalogRefused) {
                $result['skipped'][] = $entry->slug;

                continue;
            }

            if ($outcome !== null) {
                $result[$outcome][] = $entry->slug;
            }
        }

        return $result;
    }
}
