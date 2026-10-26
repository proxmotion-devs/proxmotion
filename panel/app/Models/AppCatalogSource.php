<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where the App Store's recipes were read from and how the last read went
 * (docs/adr/0041). The official repository is the only source for now.
 *
 * @property int $id
 * @property string $name
 * @property string $repository
 * @property string $ref
 * @property ?string $commit
 * @property ?Carbon $fetched_at
 * @property ?string $error
 */
class AppCatalogSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AppCatalogEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AppCatalogEntry::class);
    }
}
