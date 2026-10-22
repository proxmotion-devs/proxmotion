<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\Hostname;
use App\Rules\NetworkList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Panel settings page (docs/adr/0038). Every setting is sent; empty
 * optional fields arrive as null.
 */
class PanelSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cross_cluster_moves' => ['required', 'boolean'],
            'move_deadline_minutes' => ['required', 'integer', 'min:30', 'max:10080'],
            'move_bwlimit_kib' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            // 0: from Proxmox's next free ID; Proxmox's VMIDs start at 100.
            'min_vmid' => ['required', 'integer', 'min:0', 'max:999999999', function (string $attribute, mixed $value, Closure $fail) {
                if ((int) $value > 0 && (int) $value < 100) {
                    $fail(__('Enter 0 or a VMID of at least 100.'));
                }
            }],
            'provisioning_deadline_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'verify_dns_name' => ['nullable', 'string', 'max:253', new Hostname],
            'protected_networks' => ['nullable', 'string', 'max:4000', new NetworkList],
            'drift_stopped_grace_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'console_max_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'console_idle_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'docs_url' => ['nullable', 'string', 'max:255', 'url:https'],
        ];
    }

    /**
     * The settings with the types PanelSettings stores.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();
        $optionalInt = fn (string $key): ?int => isset($data[$key]) ? (int) $data[$key] : null;
        $optionalString = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return [
            'cross_cluster_moves' => (bool) $data['cross_cluster_moves'],
            'move_deadline_minutes' => (int) $data['move_deadline_minutes'],
            'move_bwlimit_kib' => $optionalInt('move_bwlimit_kib'),
            'min_vmid' => (int) $data['min_vmid'],
            'provisioning_deadline_minutes' => (int) $data['provisioning_deadline_minutes'],
            'verify_dns_name' => ($name = $optionalString('verify_dns_name')) === null ? null : strtolower($name),
            'protected_networks' => NetworkList::canonical($optionalString('protected_networks')),
            'drift_stopped_grace_minutes' => (int) $data['drift_stopped_grace_minutes'],
            'console_max_minutes' => (int) $data['console_max_minutes'],
            'console_idle_minutes' => (int) $data['console_idle_minutes'],
            'docs_url' => $optionalString('docs_url'),
        ];
    }
}
