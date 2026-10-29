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
            // How many snapshots and backups its servers may have; 0: none (docs/adr/0051).
            'snapshot_slots' => $this->snapshot_slots,
            'backup_slots' => $this->backup_slots,
            // How often its servers may be reinstalled through the API; null for unlimited (docs/adr/0045).
            'reinstall_limit' => $this->reinstall_limit === null || $this->reinstall_period_days === null
                ? null
                : ['count' => $this->reinstall_limit, 'period_days' => $this->reinstall_period_days],
            'network_models' => $this->network_models->map(fn (NetworkModel $model) => $model->value)->values(),
            // The OS templates its servers may use; empty for any (docs/adr/0040).
            'os_templates' => $this->osTemplates->pluck('uuid')->values(),
        ];
    }
}
