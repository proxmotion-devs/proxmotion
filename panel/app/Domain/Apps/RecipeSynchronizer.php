<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;

/**
 * Stores a downloaded recipe as an app's upstream version (docs/adr/0015,
 * 0016 section 2, 0042). Each new version is kept next to the earlier ones and
 * published; installations keep the version they were installed with. An app
 * an administrator edited in place keeps its edits: the new version is stored
 * but not offered.
 */
final class RecipeSynchronizer
{
    /**
     * @param  array<string, string>  $files  the scripts and includes the recipe uses
     * @return ?string created, updated or held (edited in place), or null when that version is stored already
     *
     * @throws PackageRefused when a custom app has the slug, or a version in use changed
     */
    public function store(string $slug, Recipe $recipe, array $files): ?string
    {
        $checksum = self::checksum($recipe, $files);
        $app = AppRecipe::query()->with('latestVersion')->where('slug', $slug)->first();

        // An administrator's custom app took the slug first (docs/adr/0018, section 2): it is never replaced.
        if ($app !== null && $app->isCustom()) {
            throw new PackageRefused('A custom app has this slug.');
        }

        $created = $app === null;
        $app ??= (new AppRecipe)->forceFill(['slug' => $slug, 'source' => AppRecipe::SOURCE_BUNDLED, 'is_active' => true]);
        // An administrator edited the app in place (docs/adr/0018, section 2): new versions are kept, not offered.
        $modified = $app->isModified();
        $version = $created ? null : $app->versions()->where('origin', AppRecipeVersion::ORIGIN_UPSTREAM)->where('version', $recipe->version)->first();

        if ($version !== null && $version->checksum === $checksum) {
            return null;
        }

        if ($version !== null && $version->inUse()) {
            throw new PackageRefused('This version is used by installations; raise the version number to change it.');
        }

        if (! $modified) {
            $app->forceFill(['name' => $recipe->name, 'category' => $recipe->category]);
        }

        $app->save();
        $version ??= (new AppRecipeVersion)->forceFill(['app_recipe_id' => $app->id, 'version' => $recipe->version, 'origin' => AppRecipeVersion::ORIGIN_UPSTREAM]);
        $version->forceFill([
            'format' => $recipe->format,
            'definition' => $recipe->definition,
            'files' => $files,
            'checksum' => $checksum,
            'reviewed_at' => now(),
        ]);

        // An older version (a downgrade) is stored but not offered.
        $latest = ! $modified && ($app->latestVersion === null || version_compare($recipe->version, $app->latestVersion->version, '>='));
        $version->forceFill(['status' => $latest ? AppRecipeVersionStatus::Published : AppRecipeVersionStatus::Archived])->save();

        if ($latest) {
            if ($app->latestVersion !== null && ! $app->latestVersion->is($version)) {
                $app->latestVersion->forceFill(['status' => AppRecipeVersionStatus::Archived])->save();
            }

            $app->forceFill(['latest_version_id' => $version->id])->save();
        }

        return $modified ? 'held' : ($created ? 'created' : 'updated');
    }

    /**
     * @param  array<string, string>  $files
     */
    public static function checksum(Recipe $recipe, array $files): string
    {
        ksort($files);

        return hash('sha256', json_encode([$recipe->definition, $files], JSON_THROW_ON_ERROR));
    }
}
