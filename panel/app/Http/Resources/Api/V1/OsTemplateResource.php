<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\OsTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OsTemplate
 */
class OsTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'family' => $this->family,
            'version' => $this->version,
            // A derivative's own os-release ID and version; family and version name its base (docs/adr/0022).
            'distribution' => $this->distribution,
            'distribution_version' => $this->distribution_version,
        ];
    }
}
