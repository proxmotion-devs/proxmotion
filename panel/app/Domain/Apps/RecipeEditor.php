<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TextDiff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin recipe editor (docs/adr/0018): custom apps, copies, one draft
 * per app and publishing it as a new version. Bundled apps are read-only.
 * Published and archived versions never change; installations keep theirs.
 * Every change is audited with a diff against the published version.
 */
final readonly class RecipeEditor
{
    /** Paths a recipe file may have (recipe-v2.schema.json, $defs/script). */
    public const FILE_PATH = '/^[a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*\.(sh|ps1)$/';

    public const SLUG = '/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/';

    /** At most this much diff text is kept in one audit log entry. */
    public const MAX_DIFF_BYTES = 65536;

    public function __construct(private RecipeLoader $loader) {}

    /**
     * A new custom app with a draft made from the recipe skeleton.
     *
     * @throws ValidationException for an unusable slug or name
     */
    public function create(string $slug, string $name, User $admin, ?string $ipAddress): AppRecipe
    {
        $this->ensureFree($slug, $name);
        $skeleton = RecipeSkeleton::files($slug, $name);
        /** @var array<string, mixed> $definition */
        $definition = (array) Yaml::parse($skeleton['recipe.yaml']);
        $files = array_filter($skeleton, fn (string $path) => preg_match(self::FILE_PATH, $path) === 1, ARRAY_FILTER_USE_KEY);

        $app = DB::transaction(function () use ($slug, $name, $definition, $files) {
            $app = (new AppRecipe)->forceFill(['slug' => $slug, 'source' => AppRecipe::SOURCE_CUSTOM, 'name' => $name, 'category' => (string) ($definition['category'] ?? 'web'), 'is_active' => true]);
            $app->save();
            $this->storeDraft($app, null, $definition, $files);

            return $app;
        });

        AuditLog::record('app.created', $admin, $app, ['app' => $slug], $ipAddress);

        return $app;
    }

    /**
     * A new custom app copied from any version of another app (bundled or
     * custom); it starts as a draft 1.0.0 with the same recipe and scripts.
     *
     * @throws ValidationException for an unusable slug or name
     */
    public function copy(AppRecipeVersion $source, string $slug, string $name, User $admin, ?string $ipAddress): AppRecipe
    {
        $this->ensureFree($slug, $name);
        $definition = $source->definition;

        $app = DB::transaction(function () use ($source, $slug, $name, $definition) {
            $app = (new AppRecipe)->forceFill([
                'slug' => $slug,
                'source' => AppRecipe::SOURCE_CUSTOM,
                'name' => $name,
                'category' => (string) ($definition['category'] ?? 'web'),
                'is_active' => true,
                'copied_from_version_id' => $source->id,
            ]);
            $app->save();
            $this->storeDraft($app, '1.0.0', array_replace($definition, ['name' => $name]), $source->files);

            return $app;
        });

        AuditLog::record('app.copied', $admin, $app, ['app' => $slug, 'from' => $source->app->slug.'@'.$source->version], $ipAddress);

        return $app;
    }

    /**
     * Save the app's draft (created from the published version when there is
     * none). An invalid draft is saved too; errors() lists what to fix.
     *
     * @param  array<string, mixed>  $definition  recipe.yaml as a mapping
     * @param  array<array-key, mixed>  $files  scripts and includes by path
     *
     * @throws RecipeEditRefused for a bundled app
     * @throws ValidationException for file paths or sizes the recipe format refuses
     */
    public function saveDraft(AppRecipe $app, array $definition, array $files, User $admin, ?string $ipAddress): AppRecipeVersion
    {
        $this->ensureCustom($app);
        $files = $this->cleanFiles($files);

        $draft = DB::transaction(fn () => $this->storeDraft($app, $app->draft()?->version, $definition, $files));

        AuditLog::record('app.draft_saved', $admin, $app, [
            'app' => $app->slug,
            'version' => $draft->version,
            'diff' => $this->diff($app->latestVersion, $draft->definition, $draft->files),
        ], $ipAddress);

        return $draft;
    }

    /**
     * What keeps the draft from being published, or an empty list.
     *
     * @return list<string>
     */
    public function errors(AppRecipeVersion $draft): array
    {
        try {
            $this->loader->validate($draft->app->slug, $draft->definition, $draft->files);
        } catch (RecipeInvalid $e) {
            return $e->errors;
        }

        return [];
    }

    /**
     * @throws RecipeEditRefused when there is no draft
     */
    public function discardDraft(AppRecipe $app, User $admin, ?string $ipAddress): void
    {
        $this->ensureCustom($app);
        $draft = $app->draft() ?? throw new RecipeEditRefused('This app has no draft.');

        if ($app->latestVersion === null) {
            throw new RecipeEditRefused('The draft of an app that was never published cannot be discarded; delete the app instead.');
        }

        $draft->delete();
        AuditLog::record('app.draft_discarded', $admin, $app, ['app' => $app->slug], $ipAddress);
    }

    /**
     * Publish the draft as the app's new version: the next version after
     * every earlier one (patch, minor or major), offered to new
     * installations at once. The previously published version is archived.
     *
     * @param  'patch'|'minor'|'major'  $bump
     *
     * @throws RecipeEditRefused when there is no draft
     * @throws RecipeInvalid when the draft does not validate
     */
    public function publish(AppRecipe $app, string $bump, User $admin, ?string $ipAddress): AppRecipeVersion
    {
        $this->ensureCustom($app);
        $draft = $app->draft() ?? throw new RecipeEditRefused('This app has no draft.');
        $version = self::bump($this->versionsOf($app), $bump);
        $definition = array_replace($draft->definition, ['slug' => $app->slug, 'version' => $version]);

        ['recipe' => $recipe, 'files' => $files] = $this->loader->validate($app->slug, $definition, $draft->files);
        $previous = $app->latestVersion;

        DB::transaction(function () use ($app, $draft, $recipe, $files, $version, $previous, $admin) {
            $draft->forceFill([
                'version' => $version,
                'format' => $recipe->format,
                'definition' => $recipe->definition,
                'files' => $files,
                'checksum' => RecipeSynchronizer::checksum($recipe, $files),
                'status' => AppRecipeVersionStatus::Published,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $previous?->forceFill(['status' => AppRecipeVersionStatus::Archived])->save();
            $app->forceFill(['latest_version_id' => $draft->id, 'name' => $recipe->name, 'category' => $recipe->category])->save();
        });

        AuditLog::record('app.published', $admin, $app, [
            'app' => $app->slug,
            'version' => $version,
            'previous' => $previous?->version,
            'diff' => $this->diff($previous, $draft->definition, $draft->files),
        ], $ipAddress);

        return $draft->refresh();
    }

    /**
     * Delete a custom app that was never installed, with all its versions.
     *
     * @throws RecipeEditRefused for a bundled app or one with installations
     */
    public function delete(AppRecipe $app, User $admin, ?string $ipAddress): void
    {
        $this->ensureCustom($app);

        if ($app->installations()->exists()) {
            throw new RecipeEditRefused('This app has installations; deactivate it instead.');
        }

        DB::transaction(function () use ($app) {
            $app->forceFill(['latest_version_id' => null])->save();
            $app->versions()->delete();
            $app->delete();
        });

        AuditLog::record('app.deleted', $admin, null, ['app' => $app->slug], $ipAddress);
    }

    /**
     * The next version after $existing, e.g. 1.2.3 → 1.2.4 (patch), 1.3.0, 2.0.0.
     *
     * @param  list<string>  $existing
     * @param  'patch'|'minor'|'major'  $bump
     */
    public static function bump(array $existing, string $bump): string
    {
        $highest = '0.0.0';

        foreach ($existing as $version) {
            if (preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 && version_compare($version, $highest, '>')) {
                $highest = $version;
            }
        }

        if ($existing === []) {
            return '1.0.0';
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', $highest));

        return match ($bump) {
            'major' => ($major + 1).'.0.0',
            'minor' => $major.'.'.($minor + 1).'.0',
            default => $major.'.'.$minor.'.'.($patch + 1),
        };
    }

    /**
     * Version numbers the app has used, drafts aside.
     *
     * @return list<string>
     */
    private function versionsOf(AppRecipe $app): array
    {
        return array_values(array_map(
            fn (AppRecipeVersion $version) => $version->version,
            $app->versions()->where('status', '!=', AppRecipeVersionStatus::Draft)->get(['id', 'version'])->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $files
     */
    private function storeDraft(AppRecipe $app, ?string $version, array $definition, array $files): AppRecipeVersion
    {
        $draft = $app->draft();
        $version ??= self::bump($this->versionsOf($app), 'patch');
        // The slug is the app's; the version is set when publishing.
        $definition = array_replace($definition, ['slug' => $app->slug, 'version' => $version]);

        $draft ??= (new AppRecipeVersion)->forceFill(['app_recipe_id' => $app->id, 'status' => AppRecipeVersionStatus::Draft]);
        $draft->forceFill([
            'version' => $version,
            'format' => (int) ($definition['format'] ?? 1),
            'definition' => $definition,
            'files' => $files,
            'checksum' => hash('sha256', json_encode([$definition, $files], JSON_THROW_ON_ERROR)),
        ])->save();

        return $draft;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function cleanFiles(array $files): array
    {
        $clean = [];
        $errors = [];

        foreach ($files as $path => $content) {
            $path = (string) $path;

            if (preg_match(self::FILE_PATH, $path) !== 1 || str_contains($path, '..')) {
                $errors['files'][] = __('The file name :path is not allowed (lower-case letters, digits, dashes, folders, ending in .sh or .ps1).', ['path' => Str::limit($path, 80)]);

                continue;
            }

            if (! is_string($content)) {
                $errors['files'][] = __('The file :path has no text.', ['path' => $path]);

                continue;
            }

            // Scripts are written with Windows line ends from some browsers; bash needs LF.
            $clean[$path] = str_replace("\r\n", "\n", $content);
        }

        if (count($clean) > 50) {
            $errors['files'][] = __('A recipe can have at most 50 files.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($clean);

        return $clean;
    }

    /**
     * @throws ValidationException
     */
    private function ensureFree(string $slug, string $name): void
    {
        $errors = [];

        if (preg_match(self::SLUG, $slug) !== 1) {
            $errors['slug'][] = __('The slug must have 3 to 50 lower-case letters, digits and dashes, and start and end with a letter or digit.');
        } elseif (AppRecipe::query()->where('slug', $slug)->exists() || in_array($slug, $this->loader->slugs(), true)) {
            $errors['slug'][] = __('This slug is already used by another app.');
        }

        if (trim($name) === '' || mb_strlen($name) > 80) {
            $errors['name'][] = __('The name must have 1 to 80 characters.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws RecipeEditRefused
     */
    private function ensureCustom(AppRecipe $app): void
    {
        if (! $app->isCustom()) {
            throw new RecipeEditRefused('Bundled apps are read-only; copy the app to change it.');
        }
    }

    /**
     * The diff of recipe.yaml and every file against a version (or against nothing).
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, string>  $files
     */
    private function diff(?AppRecipeVersion $base, array $definition, array $files): string
    {
        $old = $base === null ? [] : ['recipe.yaml' => self::yaml($base->definition)] + $base->files;
        $new = ['recipe.yaml' => self::yaml($definition)] + $files;
        $diff = '';

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $path) {
            $diff .= TextDiff::unified($old[$path] ?? '', $new[$path] ?? '', $path);
        }

        return strlen($diff) > self::MAX_DIFF_BYTES ? substr($diff, 0, self::MAX_DIFF_BYTES)."\n… (diff cut at 64 KiB)\n" : $diff;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function yaml(array $definition): string
    {
        // The version changes with every publish; leave it out so the diff shows real changes.
        unset($definition['version']);

        return Yaml::dump($definition, 6, 4);
    }
}
