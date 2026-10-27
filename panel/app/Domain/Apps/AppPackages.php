<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * App packages (docs/adr/0042): a .proxapp file is a zip of one recipe
 * directory, laid out as RecipeArchive reads it. An administrator uploads one
 * to add an app or a new version of it; the version is published at once.
 * The recipes repository holds only packages, <slug>/<slug>.proxapp (PackageDirectory),
 * built from recipe sources with apps:pack.
 *
 * @phpstan-type Package array{slug: string, definition: array<string, mixed>, files: array<string, string>}
 */
final readonly class AppPackages
{
    public const EXTENSION = 'proxapp';

    /** A new app. */
    public const NEW = 'new';

    /** A newer version, or the same version changed while no installation uses it. */
    public const UPDATE = 'update';

    /** This version with this content is installed already. */
    public const CURRENT = 'current';

    /** Older than the newest version: stored, not offered. */
    public const OLDER = 'older';

    /** Edited in place by an administrator: stored, not offered (docs/adr/0018, section 2). */
    public const EDITED = 'edited';

    /** The same version number with other content, and installations use it. */
    public const CONFLICT = 'conflict';

    /** A custom app has the slug. */
    public const TAKEN = 'taken';

    public function __construct(
        private RecipeArchive $archive,
        private RecipeLoader $loader,
        private RecipeSynchronizer $synchronizer,
        private RecipeEditor $editor,
    ) {}

    /**
     * An uploaded package, validated like a recipe directory.
     *
     * @return Package
     *
     * @throws ValidationException under `package`
     */
    public function read(string $path): array
    {
        try {
            ['definition' => $definition, 'files' => $files] = $this->archive->read($path);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['package' => Arr::flatten($e->errors())]);
        }

        $slug = $definition['slug'] ?? null;

        if (! is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug) !== 1) {
            throw ValidationException::withMessages(['package' => __('The package has no valid slug in its recipe.yaml.')]);
        }

        try {
            ['files' => $files] = $this->loader->validate($slug, $definition, $files);
        } catch (RecipeInvalid $e) {
            throw ValidationException::withMessages(['package' => $e->errors]);
        }

        return ['slug' => $slug, 'definition' => $definition, 'files' => $files];
    }

    /**
     * What installing the package would do: one of the constants above, and the newest version installed.
     *
     * @param  Package  $package
     * @return array{status: string, current: ?string}
     */
    public function status(array $package): array
    {
        $recipe = Recipe::fromArray($package['definition']);
        $app = AppRecipe::query()->with('latestVersion')->where('slug', $package['slug'])->first();

        if ($app === null) {
            return ['status' => self::NEW, 'current' => null];
        }

        $current = $app->latestUpstream()?->version;

        if ($app->isCustom()) {
            return ['status' => self::TAKEN, 'current' => $current];
        }

        $same = $app->versions()->where('origin', AppRecipeVersion::ORIGIN_UPSTREAM)->where('version', $recipe->version)->first();

        $status = match (true) {
            $same !== null && $same->checksum === RecipeSynchronizer::checksum($recipe, $package['files']) => self::CURRENT,
            $same !== null && $same->inUse() => self::CONFLICT,
            $app->isModified() => self::EDITED,
            $current !== null && version_compare($recipe->version, $current, '<') => self::OLDER,
            default => self::UPDATE,
        };

        return ['status' => $status, 'current' => $current];
    }

    /**
     * The diff from the newest installed version to the package (from nothing for a new app).
     *
     * @param  Package  $package
     */
    public function diff(array $package): string
    {
        $app = AppRecipe::query()->where('slug', $package['slug'])->first();
        $to = (new AppRecipeVersion)->forceFill(['definition' => $package['definition'], 'files' => $package['files']]);

        return $this->editor->diffBetween($app === null || $app->isCustom() ? null : $app->latestUpstream(), $to);
    }

    /**
     * Install a package: a new app, or a new version of the app. Returns what the
     * synchronizer did: created, updated, held, or null when it is installed already.
     *
     * @param  Package  $package
     *
     * @throws PackageRefused
     */
    public function install(array $package, ?User $admin, ?string $ipAddress): ?string
    {
        $recipe = Recipe::fromArray($package['definition']);
        $result = DB::transaction(fn () => $this->synchronizer->store($package['slug'], $recipe, $package['files']));

        if ($result !== null) {
            $action = ['created' => 'app.package_installed', 'updated' => 'app.package_updated', 'held' => 'app.update_held'][$result];
            AuditLog::record($action, $admin, null, ['app' => $package['slug'], 'version' => $recipe->version], $ipAddress);
        }

        return $result;
    }

    /**
     * Remove an app installed from a package. An app with installations cannot be
     * removed (their version is kept); deactivate it instead.
     *
     * @throws PackageRefused
     */
    public function remove(AppRecipe $app, ?User $admin, ?string $ipAddress): void
    {
        if ($app->isCustom()) {
            throw new PackageRefused('Custom apps are deleted in the editor.');
        }

        if ($app->inUse()) {
            throw new PackageRefused('This app has installations; deactivate it instead.');
        }

        DB::transaction(function () use ($app) {
            $app->installations()->delete();
            $app->forceFill(['latest_version_id' => null])->save();
            $app->versions()->delete();
            $app->delete();
        });

        AuditLog::record('app.removed', $admin, null, ['app' => $app->slug], $ipAddress);
    }

    /**
     * What the package of one recipe of a source directory holds, by path inside
     * <slug>/: recipe.yaml as written, the files it uses, and its README.
     *
     * @return array<string, string>
     *
     * @throws RecipeInvalid
     */
    public function entries(string $directory, string $slug): array
    {
        ['files' => $files] = (new RecipeLoader($directory))->load($slug);
        $entries = ['recipe.yaml' => (string) file_get_contents($directory.'/'.$slug.'/recipe.yaml')] + $files;

        if (is_file($directory.'/'.$slug.'/README.md') && ! is_link($directory.'/'.$slug.'/README.md')) {
            $entries['README.md'] = (string) file_get_contents($directory.'/'.$slug.'/README.md');
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The package of one recipe of a source directory, as zip bytes.
     *
     * @throws RecipeInvalid
     */
    public function pack(string $directory, string $slug): string
    {
        $entries = $this->entries($directory, $slug);

        // ZipArchive writes to a file: the panel's own storage, not the system temporary directory.
        $temporary = storage_path('framework/recipes');
        File::ensureDirectoryExists($temporary);
        $path = $temporary.'/'.Str::uuid()->toString().'.'.self::EXTENSION;

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The package could not be created.');
            }

            foreach ($entries as $name => $content) {
                $zip->addFromString($slug.'/'.$name, $content);
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
