<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\NetworkModel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The form uses friendlier units (disk in GiB, traffic in GB); they are
 * converted to the stored units (MiB, bytes) in packageData().
 */
class PackageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'cpu_cores' => ['required', 'integer', 'between:1,512'],
            'memory_mib' => ['required', 'integer', 'between:128,4194304'],
            'disk_gib' => ['required', 'integer', 'between:1,65536'],
            'traffic_gb' => ['nullable', 'integer', 'between:1,10000000'],
            'port_speed_mbps' => ['nullable', 'integer', 'between:1,400000'],
            'ipv4_count' => ['required', 'integer', 'between:0,64'],
            'ipv6_count' => ['required', 'integer', 'between:0,64'],
            'snapshot_slots' => ['required', 'integer', 'between:0,100'],
            'backup_slots' => ['required', 'integer', 'between:0,100'],
            'network_models' => ['required', 'array', 'min:1'],
            'network_models.*' => ['distinct', Rule::enum(NetworkModel::class)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            // Empty: any cluster of the ordered location.
            'cluster_ids' => ['array'],
            'cluster_ids.*' => ['integer', 'distinct', Rule::exists('clusters', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function clusterIds(): array
    {
        return array_values(array_map('intval', $this->array('cluster_ids')));
    }

    /**
     * @return array<string, mixed>
     */
    public function packageData(): array
    {
        $data = $this->safe()->except(['disk_gib', 'traffic_gb', 'cluster_ids']);
        $data['disk_mib'] = $this->integer('disk_gib') * 1024;
        $data['traffic_bytes'] = $this->filled('traffic_gb') ? $this->integer('traffic_gb') * 1000 ** 3 : null;
        $data['port_speed_mbps'] = $this->filled('port_speed_mbps') ? $this->integer('port_speed_mbps') : null;

        return $data;
    }
}
