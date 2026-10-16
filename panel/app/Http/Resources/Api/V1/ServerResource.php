<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\Server;
use App\Support\Network\Cidr;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A server as the billing system sees it. Never contains the root password:
 * it is fetched once through GET /servers/{id}/password.
 *
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /** The live power state, when the controller asked Proxmox for it. */
    private ?string $powerState = null;

    private bool $withPowerState = false;

    public function withPowerState(?string $state): self
    {
        $this->powerState = $state;
        $this->withPowerState = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'external_id' => $this->external_id,
            'external_client_id' => $this->external_client_id,
            'hostname' => $this->hostname,
            'status' => $this->status->value,
            'suspension_reason' => $this->suspension_reason,
            // New CPU or memory wait for a restart of the server (start/reboot through the API).
            'reboot_required' => $this->reboot_required,
            // "running", "stopped" or null when Proxmox could not be asked; only on GET /servers/{id}.
            'power_state' => $this->when($this->withPowerState, fn () => $this->powerState),
            'network_model' => $this->network_model->value,
            'package' => $this->package === null ? null : ['id' => $this->package->uuid, 'name' => $this->package->name],
            'location' => ['id' => $this->location->uuid, 'name' => $this->location->name],
            'os_template' => $this->osTemplate === null ? null : ['id' => $this->osTemplate->uuid, 'name' => $this->osTemplate->name],
            'resources' => [
                'cpu_cores' => $this->cpu_cores,
                'memory_mib' => $this->memory_mib,
                'disk_mib' => $this->disk_mib,
                'port_speed_mbps' => $this->port_speed_mbps,
                'traffic_bytes' => $this->traffic_bytes,
            ],
            'addresses' => $this->ipAddresses->map(fn (IpAddress $address) => [
                'address' => $address->address,
                'version' => $address->pool->version->value,
                // The first address of each version is its primary (docs/adr/0011).
                'primary' => $this->ipAddresses->first(fn (IpAddress $other) => $other->pool->version === $address->pool->version)?->is($address) ?? false,
                // As configured by cloud-init (NetworkConfig): routed IPv4 addresses are /32.
                'prefix' => $address->pool->version === IpVersion::V4 && $this->network_model === NetworkModel::Routed
                    ? 32
                    : Cidr::parse($address->pool->network)->prefix,
                'gateway' => $address->pool->gateway,
                'ptr' => $address->ptr,
            ])->values(),
            // A new root password the billing system has not fetched yet.
            'root_password_available' => $this->root_password !== null && $this->root_password_unrevealed,
            'created_at' => $this->created_at?->toIso8601String(),
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'terminated_at' => $this->terminated_at?->toIso8601String(),
        ];
    }
}
