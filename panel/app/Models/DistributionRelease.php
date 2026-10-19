<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Infrastructure\DistributionCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A release of a distribution offered in the OS template form ("24.04",
 * "rolling" for every version). A derivative's release names the release of
 * its base it is matched as (Pardus 23 → Debian 12, docs/adr/0025).
 *
 * @property int $id
 * @property int $distribution_id
 * @property string $version
 * @property string|null $base_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Distribution $distribution
 */
#[Fillable(['version', 'base_version'])]
class DistributionRelease extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(DistributionCatalog::class)->forget());
        static::deleted(fn () => app(DistributionCatalog::class)->forget());
    }

    /**
     * @return BelongsTo<Distribution, $this>
     */
    public function distribution(): BelongsTo
    {
        return $this->belongsTo(Distribution::class);
    }
}
