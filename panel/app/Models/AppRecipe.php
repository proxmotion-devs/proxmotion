<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\Recipe;
use App\Enums\AppRecipeVersionStatus;
use Database\Factories\AppRecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An app of the App Marketplace (docs/adr/0015, 0016): its identity, source
 * and active flag. The recipe itself lives in its versions; new
 * installations use the latest approved one.
 *
 * @property int $id
 * @property string $slug
 * @property string $source bundled (panel/recipes, read-only) or custom (admin editor, docs/adr/0018); catalogs: docs/adr/0017
 * @property int|null $latest_version_id the published version
 * @property int|null $copied_from_version_id the version a custom app was copied from
 * @property string $name of the latest version
 * @property string $category of the latest version
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppRecipeVersion|null $latestVersion
 * @property-read AppRecipeVersion|null $copiedFrom
 */
class AppRecipe extends Model
{
    /** @use HasFactory<AppRecipeFactory> */
    use HasFactory;

    public const SOURCE_BUNDLED = 'bundled';

    public const SOURCE_CUSTOM = 'custom';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsTo<AppRecipeVersion, $this>
     */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(AppRecipeVersion::class, 'latest_version_id');
    }

    /**
     * @return BelongsTo<AppRecipeVersion, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(AppRecipeVersion::class, 'copied_from_version_id');
    }

    /**
     * The draft being edited (custom apps, docs/adr/0018 §3), if any.
     */
    public function draft(): ?AppRecipeVersion
    {
        return $this->versions()->where('status', AppRecipeVersionStatus::Draft)->first();
    }

    public function isCustom(): bool
    {
        return $this->source === self::SOURCE_CUSTOM;
    }

    /**
     * @return HasMany<AppRecipeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AppRecipeVersion::class);
    }

    /**
     * The version new installations get.
     */
    public function current(): AppRecipeVersion
    {
        return $this->latestVersion ?? throw new LogicException("App {$this->slug} has no approved version.");
    }

    /**
     * The latest version's recipe.
     */
    public function recipe(): Recipe
    {
        return $this->current()->recipe();
    }

    /**
     * @return HasMany<AppInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }
}
