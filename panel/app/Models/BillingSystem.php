<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Database\Factories\BillingSystemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A billing system (WHMCS, WISECP, …) that uses the API (docs/adr/0008). Its
 * tokens only see its own servers, and its service ids (external_id) are
 * unique within it. Tokens can be replaced without the servers changing hands.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class BillingSystem extends Model
{
    /** @use HasFactory<BillingSystemFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * Choices for admin forms.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(self::query()->orderBy('name')->get(['uuid', 'name'])
            ->map(fn (self $system) => ['value' => $system->uuid, 'label' => $system->name])
            ->all());
    }

    /**
     * @return HasMany<ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<WebhookEndpoint, $this>
     */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }
}
