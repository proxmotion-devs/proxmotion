<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OsPlatform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An operating system administrators offer OS templates of ("Ubuntu"), with
 * the versions they add (docs/adr/0026). The platform picks the guest
 * scripts; the slug, made from the name, is the template's `distribution`.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OsPlatform $platform
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OperatingSystemVersion> $versions
 */
#[Fillable(['name', 'platform', 'sort_order'])]
class OperatingSystem extends Model
{
    protected static function booted(): void
    {
        static::creating(function (OperatingSystem $system) {
            $system->slug ??= self::freeSlug($system->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => OsPlatform::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<OperatingSystemVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(OperatingSystemVersion::class);
    }

    /** "Ubuntu" → ubuntu, "Rocky Linux" → rocky-linux; a number is added when it is taken. */
    public static function freeSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 28, '') ?: 'os';
        $slug = $base;

        for ($n = 2; self::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
