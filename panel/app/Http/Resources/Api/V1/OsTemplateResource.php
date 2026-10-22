<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Infrastructure\Distributions;
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
            // Deprecated (docs/adr/0024 §6): the system's slug with the names
            // v1 always used (archlinux, opensuse), as in an app's `os`.
            'family' => Distributions::apiName($this->distribution),
            'version' => $this->version,
            // The system's slug and version from the administrators' list (docs/adr/0026).
            'distribution' => $this->distribution,
            'distribution_version' => $this->version,
            'os_family' => $this->family->value,
        ];
    }
}
