<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppEntitlementSource;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The right to install one paid app on one server (docs/adr/0019). A server
 * has at most one active (not revoked) entitlement per app. Only the App
 * Marketplace code writes entitlements, so nothing is fillable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int $app_recipe_id
 * @property AppEntitlementSource $source
 * @property string|null $reference the billing system's own id, e.g. of an addon
 * @property int|null $user_id
 * @property int|null $api_token_id
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read AppRecipe $recipe
 */
class AppEntitlement extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => AppEntitlementSource::class,
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<AppRecipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(AppRecipe::class, 'app_recipe_id');
    }
}
