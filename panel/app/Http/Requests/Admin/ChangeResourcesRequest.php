<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Package;
use App\Models\Server;
use App\Provisioning\ResourceChange;
use App\Provisioning\ServerSpec;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The admin form for new resources: every value is sent (the form starts
 * with the current ones); "package" empty means custom resources.
 */
class ChangeResourcesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Same limits as the server and package forms.
        return [
            'package' => ['nullable', 'string', Rule::exists('packages', 'uuid')],
            'cpu_cores' => ['required', 'integer', 'between:1,512'],
            'memory_mib' => ['required', 'integer', 'between:128,4194304'],
            'disk_gib' => ['required', 'integer', 'between:1,65536'],
            'ipv4_count' => ['required', 'integer', 'between:0,64'],
            'ipv6_count' => ['required', 'integer', 'between:0,64'],
            'port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'traffic_gb' => ['nullable', 'integer', 'between:1,10000000'],
            'reboot' => ['boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $package = $this->package();

            if ($validator->errors()->isEmpty() && $package !== null && ! $package->network_models->contains($this->targetServer()->network_model)) {
                $validator->errors()->add('package', __('This package does not allow the network model of the server.'));
            }
        }];
    }

    public function toChange(): ResourceChange
    {
        return new ResourceChange(
            spec: new ServerSpec(
                cpuCores: $this->integer('cpu_cores'),
                memoryMib: $this->integer('memory_mib'),
                diskMib: $this->integer('disk_gib') * 1024,
                ipv4Count: $this->integer('ipv4_count'),
                ipv6Count: $this->integer('ipv6_count'),
                portSpeedMbps: $this->filled('port_speed_mbps') ? $this->integer('port_speed_mbps') : null,
                trafficBytes: $this->filled('traffic_gb') ? $this->integer('traffic_gb') * 1000 ** 3 : null,
            ),
            package: $this->package(),
            reboot: $this->boolean('reboot'),
        );
    }

    private function package(): ?Package
    {
        return $this->filled('package') ? Package::query()->where('uuid', $this->string('package'))->first() : null;
    }

    private function targetServer(): Server
    {
        $server = $this->route('server');

        return $server instanceof Server ? $server : abort(404);
    }
}
