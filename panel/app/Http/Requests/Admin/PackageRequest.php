<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\NetworkModel;
use App\Models\FirewallRuleset;
use App\Provisioning\ResourceLimits;
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
            // Empty: unlimited (docs/adr/0034).
            ...ResourceLimits::rules(),
            // New servers start with these rules and its default policy (docs/adr/0035 §3).
            'firewall_ruleset' => ['nullable', 'string', Rule::exists('firewall_rulesets', 'uuid')],
            'ipv4_count' => ['required', 'integer', 'between:0,64'],
            'ipv6_count' => ['required', 'integer', 'between:0,64'],
            'snapshot_slots' => ['required', 'integer', 'between:0,100'],
            'backup_slots' => ['required', 'integer', 'between:0,100'],
            'network_models' => ['required', 'array', 'min:1'],
            'network_models.*' => ['distinct', Rule::enum(NetworkModel::class)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            // Empty: any node; otherwise the groups of the ordered location (docs/adr/0020).
            'placement_group_ids' => ['array'],
            'placement_group_ids.*' => ['integer', 'distinct', Rule::exists('placement_groups', 'id')],
            // Empty: any OS template (docs/adr/0040).
            'os_template_ids' => ['array'],
            'os_template_ids.*' => ['integer', 'distinct', Rule::exists('os_templates', 'id')],
        ];
    }

    /**
     * @return list<int>
     */
    public function placementGroupIds(): array
    {
        return array_values(array_map('intval', $this->array('placement_group_ids')));
    }

    /**
     * @return list<int>
     */
    public function osTemplateIds(): array
    {
        return array_values(array_map('intval', $this->array('os_template_ids')));
    }

    /**
     * @return array<string, mixed>
     */
    public function packageData(): array
    {
        $data = $this->safe()->except(['disk_gib', 'traffic_gb', 'placement_group_ids', 'os_template_ids', 'firewall_ruleset']);
        $data['firewall_ruleset_id'] = $this->filled('firewall_ruleset') ? FirewallRuleset::query()->where('uuid', $this->string('firewall_ruleset'))->value('id') : null;
        $data['disk_mib'] = $this->integer('disk_gib') * 1024;
        $data['traffic_bytes'] = $this->filled('traffic_gb') ? $this->integer('traffic_gb') * 1000 ** 3 : null;
        $data['port_speed_mbps'] = $this->filled('port_speed_mbps') ? $this->integer('port_speed_mbps') : null;

        return [...$data, ...ResourceLimits::fromRequest($this)->columns()];
    }
}
