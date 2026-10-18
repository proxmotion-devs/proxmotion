<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Copies the bundled recipes with their scripts into the database
 * (docs/adr/0015, 0016 §2). Each new version is stored next to the earlier
 * ones and approved (bundled recipes ship with the panel); installations
 * keep the version they were installed with. New apps are active; an
 * administrator's choice to deactivate one is kept. Recipes no longer in
 * panel/recipes are deactivated, never deleted.
 */
final readonly class RecipeSynchronizer
{
    public function __construct(private RecipeLoader $loader) {}

    /**
     * @return array{created: list<string>, updated: list<string>, held: list<string>, deactivated: list<string>, skipped: list<string>}
     *
     * @throws RecipeInvalid before anything is written, also when a version
     *                       in use changed without a new version number
     */
    public function sync(): array
    {
        $recipes = $this->loader->all();
        $result = ['created' => [], 'updated' => [], 'held' => [], 'deactivated' => [], 'skipped' => []];

        DB::transaction(function () use ($recipes, &$result) {
            foreach ($recipes as $slug => ['recipe' => $recipe, 'files' => $files]) {
                $checksum = self::checksum($recipe, $files);
                $app = AppRecipe::query()->with('latestVersion')->where('slug', $slug)->where('source', AppRecipe::SOURCE_BUNDLED)->first();

                // An administrator's custom app took the slug first (docs/adr/0018 §2): it is never replaced.
                if ($app === null && AppRecipe::query()->where('slug', $slug)->exists()) {
                    $result['skipped'][] = $slug;

                    continue;
                }

                $created = $app === null;
                $app ??= (new AppRecipe)->forceFill(['slug' => $slug, 'source' => AppRecipe::SOURCE_BUNDLED, 'is_active' => true]);
                // An administrator edited the app in place (docs/adr/0018 §2): new versions are kept, not offered.
                $modified = $app->isModified();
                $version = $created ? null : $app->versions()->where('origin', AppRecipeVersion::ORIGIN_UPSTREAM)->where('version', $recipe->version)->first();

                if ($version !== null && $version->checksum === $checksum) {
                    continue;
                }

                if ($version !== null && $version->inUse()) {
                    throw new RecipeInvalid($slug, ["Version {$recipe->version} changed but is used by installations: raise the version."]);
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

                // An older version in the repository (a downgrade) is stored but not offered.
                $latest = ! $modified && ($app->latestVersion === null || version_compare($recipe->version, $app->latestVersion->version, '>='));
                $version->forceFill(['status' => $latest ? AppRecipeVersionStatus::Published : AppRecipeVersionStatus::Archived])->save();

                if ($latest) {
                    if ($app->latestVersion !== null && ! $app->latestVersion->is($version)) {
                        $app->latestVersion->forceFill(['status' => AppRecipeVersionStatus::Archived])->save();
                    }

                    $app->forceFill(['latest_version_id' => $version->id])->save();
                }

                $result[$modified ? 'held' : ($created ? 'created' : 'updated')][] = $slug;
            }

            $gone = AppRecipe::query()
                ->where('source', AppRecipe::SOURCE_BUNDLED)
                ->where('is_active', true)
                ->whereNotIn('slug', array_keys($recipes))
                ->get();

            foreach ($gone as $model) {
                $model->forceFill(['is_active' => false])->save();
                $result['deactivated'][] = $model->slug;
            }
        });

        return $result;
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
