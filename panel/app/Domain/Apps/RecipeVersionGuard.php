<?php

declare(strict_types=1);

namespace App\Domain\Apps;

/**
 * Finds bundled recipes whose content changed without a higher version
 * (docs/adr/0016 §2). Installations keep the version they were installed
 * with, so the App Store refuses to replace a version in use; CI compares a pull
 * request's recipes with its base branch to catch this before release. The
 * content is what a download stores: recipe.yaml and the files it uses (a
 * README is not part of a version).
 */
final class RecipeVersionGuard
{
    /**
     * Recipes in $head that changed since $base but kept or lowered their
     * version. New and removed recipes are fine; recipes that do not
     * validate are left to apps:validate.
     *
     * @return list<string> one message per recipe
     */
    public function problems(RecipeLoader $base, RecipeLoader $head): array
    {
        $problems = [];
        $before = array_flip($base->slugs());

        foreach ($head->slugs() as $slug) {
            if (! isset($before[$slug])) {
                continue;
            }

            try {
                ['recipe' => $old, 'files' => $oldFiles] = $base->load($slug);
                ['recipe' => $new, 'files' => $newFiles] = $head->load($slug);
            } catch (RecipeInvalid) {
                continue;
            }

            if (RecipeSynchronizer::checksum($old, $oldFiles) === RecipeSynchronizer::checksum($new, $newFiles)) {
                continue;
            }

            if (! version_compare($new->version, $old->version, '>')) {
                $problems[] = "{$slug}: changed but its version is {$new->version} (was {$old->version}); raise the version in recipe.yaml.";
            }
        }

        return $problems;
    }
}
