<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FirewallRuleset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A firewall rule set customers can apply (docs/adr/0035 §3).
 *
 * @mixin FirewallRuleset
 */
class FirewallRulesetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'default_policy' => $this->default_policy,
            'rules' => $this->rules,
        ];
    }
}
