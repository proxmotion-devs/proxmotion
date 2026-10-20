<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'country_code' => $this->country_code,
            // Servers ordered here may come without a hostname (docs/adr/0031).
            'generates_hostnames' => ($this->hostname_template ?? '') !== '',
        ];
    }
}
