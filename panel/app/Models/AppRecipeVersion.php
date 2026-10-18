<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\Recipe;
use App\Enums\AppRecipeVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a recipe with its scripts (docs/adr/0016 §2). Installations
 * keep the version they were installed with; versions are never deleted
 * while an installation uses them. Only the App Marketplace code writes them.
 *
 * @property int $id
 * @property int $app_recipe_id
 * @property string $version
 * @property int $format 1 or 2
 * @property array<string, mixed> $definition the validated recipe.yaml
 * @property array<string, string> $files scripts by path, e.g. install.sh
 * @property string $checksum SHA-256 of the definition and files
 * @property string|null $signature minisign signature (docs/adr/0017)
 * @property string|null $signed_by key id of the signature
 * @property AppRecipeVersionStatus $status
 * @property string $origin upstream (panel/recipes) or local (admin editor)
 * @property int|null $trial_of_id the draft a trial version was frozen from (docs/adr/0018 §7)
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppRecipe $app
 */
class AppRecipeVersion extends Model
{
    /** Shipped in panel/recipes and stored by apps:sync. */
    public const ORIGIN_UPSTREAM = 'upstream';

    /** Written in the admin editor: custom apps, and bundled apps edited in place (docs/adr/0018 §2). */
    public const ORIGIN_LOCAL = 'local';

    /** @var Recipe|null parsed definition, kept per instance */
    private ?Recipe $parsed = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'files' => 'array',
            'status' => AppRecipeVersionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AppRecipe, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(AppRecipe::class, 'app_recipe_id');
    }

    /**
     * The draft a trial version was frozen from; null once that draft was discarded.
     *
     * @return BelongsTo<AppRecipeVersion, $this>
     */
    public function trialOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'trial_of_id');
    }

    /**
     * The administrator who published (or reviewed) the version.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<AppInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }

    /**
     * Installed with this version, or an upgrade run moves an installation to it:
     * its content must not change any more.
     */
    public function inUse(): bool
    {
        return $this->installations()->exists() || AppRun::query()->where('target_version_id', $this->id)->exists();
    }

    public function recipe(): Recipe
    {
        return $this->parsed ??= Recipe::fromArray($this->definition);
    }

    /**
     * The script of a lifecycle operation, e.g. install.
     */
    public function script(string $operation): ?string
    {
        $path = $this->recipe()->lifecycleScript($operation);

        return $path === null ? null : ($this->files[$path] ?? null);
    }
}
