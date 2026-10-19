<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Infrastructure\DistributionCatalog;
use App\Enums\OsFamily;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operating system administrators pick OS templates from (docs/adr/0025):
 * its os-release ID (`slug`), name and family, and for one built on another
 * distribution with its own version numbers, the distribution apps are
 * matched as (`base`; `same_version` when its versions are the base's).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property OsFamily $family
 * @property string|null $base
 * @property bool $same_version
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DistributionRelease> $releases
 */
#[Fillable(['slug', 'name', 'family', 'base', 'same_version', 'sort_order'])]
class Distribution extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(DistributionCatalog::class)->forget());
        static::deleted(fn () => app(DistributionCatalog::class)->forget());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'family' => OsFamily::class,
            'same_version' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<DistributionRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(DistributionRelease::class);
    }
}
