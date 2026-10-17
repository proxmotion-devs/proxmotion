<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Apps\Recipe;
use Database\Factories\AppRecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An app recipe (docs/adr/0015) as synced by apps:sync, with its scripts:
 * installs use this copy, not the files in panel/recipes.
 *
 * @property int $id
 * @property string $slug
 * @property string $source bundled (custom recipes come in Phase 5c)
 * @property string $name
 * @property string $version
 * @property string $category
 * @property array<string, mixed> $definition the validated recipe.yaml
 * @property array<string, string> $files scripts by path, e.g. install.sh
 * @property string $checksum
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AppRecipe extends Model
{
    /** @use HasFactory<AppRecipeFactory> */
    use HasFactory;

    public const SOURCE_BUNDLED = 'bundled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'files' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function recipe(): Recipe
    {
        return Recipe::fromArray($this->definition);
    }

    /**
     * @return HasMany<AppInstallation, $this>
     */
    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }
}
