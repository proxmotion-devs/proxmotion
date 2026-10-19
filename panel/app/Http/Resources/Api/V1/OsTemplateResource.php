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
            // Deprecated (docs/adr/0024 §6): the distribution and version apps are
            // matched as, with the names v1 always used (archlinux, opensuse, other).
            'family' => $this->legacyFamily(),
            'version' => $this->matchVersion(),
            'distribution' => $this->distribution,
            'distribution_version' => $this->version,
            'os_family' => $this->family->value,
        ];
    }

    private function legacyFamily(): string
    {
        if ($this->compatible_with !== null) {
            return Distributions::apiName($this->compatible_with);
        }

        return Distributions::known($this->distribution) ? Distributions::apiName($this->distribution) : 'other';
    }
}
