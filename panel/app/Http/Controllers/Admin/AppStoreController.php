<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\Catalog\AppCatalog;
use App\Domain\Apps\Catalog\CatalogRefused;
use App\Domain\Apps\Catalog\RecipeSource;
use App\Domain\Apps\RecipeEditor;
use App\Http\Controllers\Controller;
use App\Models\AppCatalogEntry;
use App\Models\AppCatalogSource;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The App Store (docs/adr/0041): the recipes of the official repository, downloaded,
 * updated and removed by administrators. Nothing is installable before it is downloaded.
 */
class AppStoreController extends Controller
{
    public function index(): Response
    {
        $source = AppCatalogSource::query()->where('name', RecipeSource::OFFICIAL)->first();
        $apps = AppRecipe::query()
            ->with([
                'latestVersion' => fn ($query) => $query->select(['id', 'app_recipe_id', 'version', 'origin', 'checksum']),
                'versions' => fn ($query) => $query->select(['id', 'app_recipe_id', 'version', 'origin', 'checksum']),
            ])
            ->get()
            ->keyBy('slug');
        $entries = $source === null ? collect() : $source->entries()->orderBy('slug')->get();
        $catalog = app(AppCatalog::class);

        return Inertia::render('admin/apps/Store', [
            'source' => $source === null ? null : [
                'repository' => $source->repository,
                'ref' => $source->ref,
                'commit' => $source->commit,
                'fetchedAt' => $source->fetched_at?->toIso8601String(),
                'error' => $source->error,
            ],
            'apps' => $entries->map(fn (AppCatalogEntry $entry) => $this->row($entry, $apps->get($entry->slug), $catalog))->values(),
        ]);
    }

    /**
     * Read the official repository again, now (the schedule does it every six hours).
     */
    public function refresh(Request $request, #[CurrentUser] User $admin): RedirectResponse
    {
        $state = app(AppCatalog::class)->refresh(RecipeSource::official());

        if ($state === null) {
            $this->toast('error', 'The App Store is being refreshed already.');

            return back();
        }

        AuditLog::record('app.catalog_refreshed', $admin, null, ['commit' => $state->commit, 'error' => $state->error], $request->ip());

        if ($state->error !== null) {
            $this->toast('error', 'The recipes could not be read. The source below says why.');
        } else {
            $this->toast('success', 'App Store refreshed.');
        }

        return back();
    }

    /**
     * Download an app of the catalog, or update the one downloaded to its version.
     */
    public function download(Request $request, AppCatalogEntry $appCatalogEntry, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $result = app(AppCatalog::class)->download($appCatalogEntry, $admin, $request->ip());
        } catch (CatalogRefused $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast(match ($result) {
            'created' => 'success',
            'updated' => 'success',
            'held' => 'info',
            default => 'info',
        }, match ($result) {
            'created' => 'App downloaded.',
            'updated' => 'App updated.',
            'held' => 'Saved for later: an administrator edited this app in place. Compare the new version in the editor.',
            default => 'The app is up to date.',
        });

        return back();
    }

    /**
     * The diff from the downloaded version to the catalog's version (or from nothing, when not downloaded).
     */
    public function diff(AppCatalogEntry $appCatalogEntry, RecipeEditor $editor): JsonResponse
    {
        abort_if($appCatalogEntry->definition === null || $appCatalogEntry->files === null, 404);

        $app = AppRecipe::query()->where('slug', $appCatalogEntry->slug)->first();
        abort_if($app?->isCustom() === true, 404);

        $to = (new AppRecipeVersion)->forceFill(['definition' => $appCatalogEntry->definition, 'files' => $appCatalogEntry->files]);

        return response()->json(['diff' => $editor->diffBetween($app?->latestUpstream(), $to)]);
    }

    /**
     * Remove a downloaded app (not one with installations: deactivate it instead).
     */
    public function remove(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            app(AppCatalog::class)->remove($appRecipe, $admin, $request->ip());
        } catch (CatalogRefused $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('success', 'App removed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AppCatalogEntry $entry, ?AppRecipe $app, AppCatalog $catalog): array
    {
        return [
            'id' => $entry->id,
            'slug' => $entry->slug,
            'name' => $entry->name ?? $entry->slug,
            'category' => $entry->category,
            'platform' => $entry->platform,
            'version' => $entry->version,
            'status' => $catalog->status($entry, $app),
            'downloadedVersion' => $app === null ? null : $catalog->newestUpstream($app)?->version,
            'errors' => $entry->errors ?? [],
            'app' => $app === null ? null : [
                'slug' => $app->slug,
                'isActive' => $app->is_active,
                'inUse' => $app->inUse(),
            ],
        ];
    }
}
