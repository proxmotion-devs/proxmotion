<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\IpVersion;
use App\Models\Package;
use App\Models\Server;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceLimits;
use App\Provisioning\ServerSpec;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/servers/{id}/package: new resources for a server (upgrade or
 * downgrade). "package" absent keeps the server's package, null makes the
 * server custom, an id switches to that package. Values not given come from
 * the new package, otherwise from the server's current resources. The network
 * model stays. Same limits as creating a server.
 */
class ChangeResourcesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'package' => ['nullable', 'string', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'resources' => ['nullable', 'array'],
            'resources.cpu_cores' => ['nullable', 'integer', 'between:1,512'],
            'resources.memory_mib' => ['nullable', 'integer', 'between:128,4194304'],
            'resources.disk_mib' => ['nullable', 'integer', 'between:1024,67108864'],
            'resources.ipv4_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.ipv6_count' => ['nullable', 'integer', 'between:0,64'],
            'resources.port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'resources.traffic_bytes' => ['nullable', 'integer', 'between:1,10000000000000000'],
            // Restart right away if CPU or memory changes wait for a restart.
            'reboot' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $package = $this->package();

            if ($validator->errors()->isEmpty() && $this->filled('package') && $package !== null && ! $package->network_models->contains($this->targetServer()->network_model)) {
                $validator->errors()->add('package', __('This package does not allow the network model of the server.'));
            }
        }];
    }

    public function toChange(): ResourceChange
    {
        $server = $this->targetServer();
        $package = $this->package();
        $base = $package !== null && $this->filled('package') ? ServerSpec::fromPackage($package) : new ServerSpec(
            cpuCores: $server->cpu_cores,
            memoryMib: $server->memory_mib,
            diskMib: $server->disk_mib,
            ipv4Count: $this->addressCount($server, IpVersion::V4),
            ipv6Count: $this->addressCount($server, IpVersion::V6),
            portSpeedMbps: $server->port_speed_mbps,
            trafficBytes: $server->traffic_bytes,
            limits: ResourceLimits::of($server),
        );
        $value = fn (string $key, ?int $default): ?int => $this->filled("resources.{$key}") ? $this->integer("resources.{$key}") : $default;

        return new ResourceChange(
            spec: new ServerSpec(
                cpuCores: (int) $value('cpu_cores', $base->cpuCores),
                memoryMib: (int) $value('memory_mib', $base->memoryMib),
                diskMib: (int) $value('disk_mib', $base->diskMib),
                ipv4Count: (int) $value('ipv4_count', $base->ipv4Count),
                ipv6Count: (int) $value('ipv6_count', $base->ipv6Count),
                portSpeedMbps: $value('port_speed_mbps', $base->portSpeedMbps),
                trafficBytes: $value('traffic_bytes', $base->trafficBytes),
                // From the package, or kept: the API takes no overrides (docs/adr/0034).
                limits: $base->limits,
            ),
            package: $package,
            reboot: $this->boolean('reboot'),
        );
    }

    /**
     * The package after the change: the given one, none for null, or the server's own when absent.
     */
    private function package(): ?Package
    {
        if (! $this->has('package')) {
            return $this->targetServer()->package;
        }

        return $this->filled('package') ? Package::query()->where('uuid', $this->string('package'))->first() : null;
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }

    private function addressCount(Server $server, IpVersion $version): int
    {
        return $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', $version))->count();
    }
}
