<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\Recipe;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRecipeVersionStatus;
use Database\Factories\AppRecipeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property string $source bundled (installed from a .proxapp package, docs/adr/0042; editable in place) or custom (admin editor, docs/adr/0018); catalogs: docs/adr/0017
 * @property int|null $latest_version_id the published version
 * @property int|null $copied_from_version_id the version a custom app was copied from
 * @property string $name of the latest version
 * @property string $category of the latest version
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AppRecipeVersion|null $latestVersion
 * @property-read AppRecipeVersion|null $copiedFrom
 * @property-read Collection<int, OperatingSystemVersion> $osVersions
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
     * The operating system versions administrators offer the app on (docs/adr/0026).
     *
     * @return BelongsToMany<OperatingSystemVersion, $this>
     */
    public function osVersions(): BelongsToMany
    {
        return $this->belongsToMany(OperatingSystemVersion::class);
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
     * A bundled app whose published version an administrator edited
     * (docs/adr/0018 §2): a package no longer replaces it.
     */
    public function isModified(): bool
    {
        return ! $this->isCustom() && $this->latestVersion?->origin === AppRecipeVersion::ORIGIN_LOCAL;
    }

    /**
     * The newest version installed from a package (bundled apps), whatever its status.
     */
    public function latestUpstream(): ?AppRecipeVersion
    {
        $newest = null;

        foreach ($this->versions()->where('origin', AppRecipeVersion::ORIGIN_UPSTREAM)->get() as $version) {
            if ($newest === null || version_compare($version->version, $newest->version, '>')) {
                $newest = $version;
            }
        }

        return $newest;
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

    /**
     * @return HasMany<AppEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(AppEntitlement::class);
    }

    /**
     * Delete the app with its versions, its removed installations and its
     * revoked entitlements (callers refuse apps in use or with active
     * entitlements first). Call inside a transaction.
     */
    public function deleteWithHistory(): void
    {
        $this->installations()->delete();
        $this->entitlements()->delete();
        $this->forceFill(['latest_version_id' => null])->save();
        $this->versions()->delete();
        $this->delete();
    }

    /**
     * Installed on a server, or tried on a test server that still has it
     * (docs/adr/0018 §7): the app cannot be deleted, only deactivated.
     */
    public function inUse(): bool
    {
        return $this->installations()
            ->where(fn ($query) => $query->where('is_trial', false)->orWhere('status', '!=', AppInstallationStatus::Removed))
            ->exists();
    }
}
