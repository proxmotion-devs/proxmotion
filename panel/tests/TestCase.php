<?php

namespace Tests;

use App\Domain\Apps\AppPackages;
use App\Domain\Apps\PackageRefused;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** The recipes syncRecipes() installs; the fixtures unless a test points elsewhere. */
    protected ?string $recipesDirectory = null;

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Install every recipe of the recipes directory (tests/Fixtures/recipes by default) as a package, as an
     * administrator does with their .proxapp files (docs/adr/0042). Returns the slugs created, updated, held or refused.
     *
     * @return array{created: list<string>, updated: list<string>, held: list<string>, skipped: list<string>}
     */
    protected function syncRecipes(): array
    {
        $loader = new RecipeLoader($this->recipesDirectory ?? base_path('tests/Fixtures/recipes'));
        $packages = app(AppPackages::class);
        $result = ['created' => [], 'updated' => [], 'held' => [], 'skipped' => []];

        foreach ($loader->slugs() as $slug) {
            try {
                ['recipe' => $recipe, 'files' => $files] = $loader->load($slug);
                $outcome = $packages->install(['slug' => $slug, 'definition' => $recipe->definition, 'files' => $files], null, null);
            } catch (RecipeInvalid|PackageRefused) {
                $result['skipped'][] = $slug;

                continue;
            }

            if ($outcome !== null) {
                $result[$outcome][] = $slug;
            }
        }

        return $result;
    }
}
