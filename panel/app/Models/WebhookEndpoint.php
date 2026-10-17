<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookEvent;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A URL of a billing system that is told about events of its servers
 * (docs/adr/0013). Requests are signed with the secret, which is shown once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $billing_system_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $active
 * @property int $failure_count deliveries that failed in a row (all attempts)
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingSystem $billingSystem
 * @property-read WebhookDelivery|null $lastDelivery
 */
#[Fillable(['url', 'events', 'active'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, HasPublicUuid;

    public const SECRET_PREFIX = 'whsec_';

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public static function newSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    public function wants(WebhookEvent $event): bool
    {
        return $this->active && in_array($event->value, $this->events, true);
    }

    /**
     * @return BelongsTo<BillingSystem, $this>
     */
    public function billingSystem(): BelongsTo
    {
        return $this->belongsTo(BillingSystem::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return HasOne<WebhookDelivery, $this>
     */
    public function lastDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->latestOfMany();
    }
}
