<?php

declare(strict_types=1);

namespace App\Domain\Apps\Catalog;

use App\Domain\Apps\Recipe;
use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeSynchronizer;
use App\Models\AppCatalogEntry;
use App\Models\AppCatalogSource;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The App Store (docs/adr/0041): the recipes a source offers, stored as catalog
 * entries when the source is refreshed, and downloaded from there. Nothing from a
 * source is installable until an administrator downloads it.
 */
final readonly class AppCatalog
{
    public const NOT_DOWNLOADED = 'not_downloaded';

    public const CURRENT = 'current';

    public const UPDATE_AVAILABLE = 'update_available';

    // Edited in place by an administrator: a newer version is kept for later (docs/adr/0018, section 2).
    public const EDITED = 'edited';

    // A custom app of an administrator has the slug.
    public const TAKEN = 'taken';

    public const INVALID = 'invalid';

    public function __construct(
        private RecipeFetcher $fetcher,
        private RecipeSynchronizer $synchronizer,
    ) {}

    /**
     * Read the source's recipes into the catalog. A source that cannot be read
     * keeps its entries and records why; apps whose recipe left the source are
     * no longer offered. Null when another refresh of the source is running.
     */
    public function refresh(RecipeSource $source): ?AppCatalogSource
    {
        $state = Cache::lock('app-catalog:'.$source->name, 900)->get(fn () => $this->read($source));

        return $state === false ? null : $state;
    }

    private function read(RecipeSource $source): AppCatalogSource
    {
        $state = AppCatalogSource::query()->firstOrNew(['name' => $source->name]);
        $state->forceFill(['repository' => $source->path ?? $source->repository, 'ref' => $source->ref]);

        try {
            $checkout = $this->fetcher->fetch($source);
        } catch (RecipeFetchFailed $e) {
            $state->forceFill(['error' => Str::limit($e->getMessage(), 900, ''), 'fetched_at' => now()])->save();

            return $state;
        }

        return DB::transaction(function () use ($state, $checkout) {
            $state->forceFill(['commit' => $checkout['commit'], 'fetched_at' => now(), 'error' => null])->save();

            $loader = new RecipeLoader($checkout['directory']);
            $slugs = $loader->slugs();

            foreach ($slugs as $slug) {
                AppCatalogEntry::query()->updateOrCreate(
                    ['app_catalog_source_id' => $state->id, 'slug' => $slug],
                    $this->recipeAttributes($loader, $slug),
                );
            }

            AppCatalogEntry::query()->where('app_catalog_source_id', $state->id)->whereNotIn('slug', $slugs)->delete();
            AppRecipe::query()
                ->where('source', AppRecipe::SOURCE_BUNDLED)
                ->where('is_active', true)
                ->whereNotIn('slug', $slugs)
                ->update(['is_active' => false]);

            return $state;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeAttributes(RecipeLoader $loader, string $slug): array
    {
        try {
            ['recipe' => $recipe, 'files' => $files] = $loader->load($slug);
        } catch (RecipeInvalid $e) {
            return ['version' => null, 'name' => null, 'category' => null, 'platform' => null, 'definition' => null, 'files' => null, 'checksum' => null, 'errors' => $e->errors];
        }

        return [
            'version' => $recipe->version,
            'name' => $recipe->name,
            'category' => $recipe->category,
            'platform' => $recipe->platform,
            'definition' => $recipe->definition,
            'files' => $files,
            'checksum' => RecipeSynchronizer::checksum($recipe, $files),
            'errors' => null,
        ];
    }

    /**
     * What the App Store shows for an entry, given the app of the same slug, if any: one of the status constants.
     */
    public function status(AppCatalogEntry $entry, ?AppRecipe $app): string
    {
        if ($entry->errors !== null) {
            return self::INVALID;
        }

        if ($app === null) {
            return self::NOT_DOWNLOADED;
        }

        if ($app->isCustom()) {
            return self::TAKEN;
        }

        if ($app->isModified()) {
            return self::EDITED;
        }

        $upstream = $this->newestUpstream($app);

        if ($upstream !== null && ($upstream->checksum === $entry->checksum || version_compare((string) $entry->version, $upstream->version, '<='))) {
            return self::CURRENT;
        }

        return self::UPDATE_AVAILABLE;
    }

    /**
     * The newest upstream version of an app; uses the versions already loaded on it.
     */
    public function newestUpstream(AppRecipe $app): ?AppRecipeVersion
    {
        $newest = null;

        foreach ($app->versions as $version) {
            if ($version->origin === AppRecipeVersion::ORIGIN_UPSTREAM && ($newest === null || version_compare($version->version, $newest->version, '>'))) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * Download an entry: a new app, or the app updated to the entry's version.
     * Returns what the synchronizer did: created, updated, held, or null when that version is stored already.
     *
     * @throws CatalogRefused
     */
    public function download(AppCatalogEntry $entry, ?User $admin, ?string $ipAddress): ?string
    {
        if ($entry->definition === null || $entry->files === null) {
            throw new CatalogRefused('This recipe does not validate, so it cannot be downloaded.');
        }

        $result = DB::transaction(fn () => $this->synchronizer->store($entry->slug, Recipe::fromArray($entry->definition), $entry->files));

        if ($result !== null) {
            $action = ['created' => 'app.downloaded', 'updated' => 'app.updated', 'held' => 'app.update_held'][$result];
            AuditLog::record($action, $admin, null, ['app' => $entry->slug, 'version' => $entry->version], $ipAddress);
        }

        return $result;
    }

    /**
     * Remove a downloaded app. An app with installations cannot be removed (their version is kept); deactivate it instead.
     *
     * @throws CatalogRefused
     */
    public function remove(AppRecipe $app, ?User $admin, ?string $ipAddress): void
    {
        if ($app->isCustom()) {
            throw new CatalogRefused('Custom apps are deleted in the editor.');
        }

        if ($app->inUse()) {
            throw new CatalogRefused('This app has installations; deactivate it instead.');
        }

        DB::transaction(function () use ($app) {
            $app->installations()->delete();
            $app->forceFill(['latest_version_id' => null])->save();
            $app->versions()->delete();
            $app->delete();
        });

        AuditLog::record('app.removed', $admin, null, ['app' => $app->slug], $ipAddress);
    }
}
