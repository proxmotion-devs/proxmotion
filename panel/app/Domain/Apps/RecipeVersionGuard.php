<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use Illuminate\Validation\ValidationException;

/**
 * Finds packages whose content changed without a higher version
 * (docs/adr/0016 §2). Installations keep the version they were installed
 * with, so installing a package refuses to replace a version in use; CI compares a pull
 * request's packages with its base branch to catch this before release. The
 * content is what a version stores: recipe.yaml and the files it uses (a
 * README is not part of a version).
 */
final readonly class RecipeVersionGuard
{
    public function __construct(private AppPackages $packages) {}

    /**
     * Packages in $head that changed since $base but kept or lowered their
     * version. New and removed apps are fine; packages that do not
     * validate are left to apps:validate.
     *
     * @return list<string> one message per app
     */
    public function problems(PackageDirectory $base, PackageDirectory $head): array
    {
        $problems = [];
        $before = array_flip($base->slugs());

        foreach ($head->slugs() as $slug) {
            if (! isset($before[$slug]) || ! is_file($base->path($slug)) || ! is_file($head->path($slug))) {
                continue;
            }

            try {
                $old = $this->packages->read($base->path($slug));
                $new = $this->packages->read($head->path($slug));
            } catch (ValidationException) {
                continue;
            }

            $oldRecipe = Recipe::fromArray($old['definition']);
            $newRecipe = Recipe::fromArray($new['definition']);

            if (RecipeSynchronizer::checksum($oldRecipe, $old['files']) === RecipeSynchronizer::checksum($newRecipe, $new['files'])) {
                continue;
            }

            if (! version_compare($newRecipe->version, $oldRecipe->version, '>')) {
                $problems[] = "{$slug}: changed but its version is {$newRecipe->version} (was {$oldRecipe->version}); raise the version in recipe.yaml.";
            }
        }

        return $problems;
    }
}
