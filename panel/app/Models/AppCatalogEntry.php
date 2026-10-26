<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recipe a source offers, as read at its last refresh (docs/adr/0041). The
 * scripts are kept here, so a download never reads the checkout again. `errors`
 * lists why the recipe does not validate; such an entry cannot be downloaded.
 *
 * @property int $id
 * @property int $app_catalog_source_id
 * @property string $slug
 * @property ?string $version
 * @property ?string $name
 * @property ?string $category
 * @property ?string $platform
 * @property ?array<string, mixed> $definition
 * @property ?array<string, string> $files
 * @property ?string $checksum
 * @property ?list<string> $errors
 */
class AppCatalogEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'files' => 'array',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AppCatalogSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(AppCatalogSource::class, 'app_catalog_source_id');
    }
}
