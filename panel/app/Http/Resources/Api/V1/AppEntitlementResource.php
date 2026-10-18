<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AppEntitlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The right to install a paid app on a server (docs/adr/0019).
 *
 * @mixin AppEntitlement
 */
class AppEntitlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'app' => [
                'id' => $this->recipe->slug,
                'name' => $this->recipe->name,
            ],
            'server' => [
                'id' => $this->server->uuid,
                'external_id' => $this->server->external_id,
            ],
            'source' => $this->source->value,
            'reference' => $this->reference,
            'active' => $this->revoked_at === null,
            'created_at' => $this->created_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
