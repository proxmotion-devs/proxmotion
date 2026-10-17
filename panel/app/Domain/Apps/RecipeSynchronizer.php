<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppRecipe;
use Illuminate\Support\Facades\DB;

/**
 * Copies the bundled recipes with their scripts into app_recipes
 * (docs/adr/0015). New recipes are active; an administrator's choice to
 * deactivate one is kept. Recipes no longer in panel/recipes are
 * deactivated, never deleted: installations keep pointing at them.
 */
final readonly class RecipeSynchronizer
{
    public function __construct(private RecipeLoader $loader) {}

    /**
     * @return array{created: list<string>, updated: list<string>, deactivated: list<string>}
     *
     * @throws RecipeInvalid before anything is written
     */
    public function sync(): array
    {
        $recipes = $this->loader->all();
        $result = ['created' => [], 'updated' => [], 'deactivated' => []];

        DB::transaction(function () use ($recipes, &$result) {
            foreach ($recipes as $slug => ['recipe' => $recipe, 'files' => $files]) {
                $checksum = hash('sha256', json_encode([$recipe->definition, $files], JSON_THROW_ON_ERROR));
                $model = AppRecipe::query()->where('slug', $slug)->first();

                if ($model !== null && $model->checksum === $checksum) {
                    continue;
                }

                $model ??= new AppRecipe;
                $created = ! $model->exists;
                $model->forceFill([
                    'slug' => $slug,
                    'source' => AppRecipe::SOURCE_BUNDLED,
                    'name' => $recipe->name,
                    'version' => $recipe->version,
                    'category' => $recipe->category,
                    'definition' => $recipe->definition,
                    'files' => $files,
                    'checksum' => $checksum,
                ]);

                if ($created) {
                    $model->is_active = true;
                }

                $model->save();
                $result[$created ? 'created' : 'updated'][] = $slug;
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
}
