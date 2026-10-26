<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\NetworkModel;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'cpu_cores' => $this->cpu_cores,
            'memory_mib' => $this->memory_mib,
            'disk_mib' => $this->disk_mib,
            'ipv4_count' => $this->ipv4_count,
            'ipv6_count' => $this->ipv6_count,
            'port_speed_mbps' => $this->port_speed_mbps,
            'traffic_bytes' => $this->traffic_bytes,
            'network_models' => $this->network_models->map(fn (NetworkModel $model) => $model->value)->values(),
            // The OS templates its servers may use; empty for any (docs/adr/0040).
            'os_templates' => $this->osTemplates->pluck('uuid')->values(),
        ];
    }
}
