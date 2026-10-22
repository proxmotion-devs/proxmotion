<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A version of an operating system ("24.04"). OS templates are of one
 * version, and apps are offered on the versions administrators tick for
 * them (docs/adr/0026).
 *
 * @property int $id
 * @property int $operating_system_id
 * @property string $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OperatingSystem $operatingSystem
 */
#[Fillable(['version'])]
class OperatingSystemVersion extends Model
{
    /**
     * @return BelongsTo<OperatingSystem, $this>
     */
    public function operatingSystem(): BelongsTo
    {
        return $this->belongsTo(OperatingSystem::class);
    }

    /**
     * @return HasMany<OsTemplate, $this>
     */
    public function osTemplates(): HasMany
    {
        return $this->hasMany(OsTemplate::class);
    }

    /**
     * @return BelongsToMany<AppRecipe, $this>
     */
    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(AppRecipe::class);
    }

    /** "Ubuntu 24.04". */
    public function label(): string
    {
        return trim($this->operatingSystem->name.' '.$this->version);
    }
}
