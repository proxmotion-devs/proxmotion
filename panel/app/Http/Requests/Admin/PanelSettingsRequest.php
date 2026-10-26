<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Servers\PasswordGenerator;
use App\Rules\Hostname;
use App\Rules\NetworkList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Traffic quotas and usage limits (docs/adr/0039); a limit left empty is off.
            'traffic_reset_day' => ['required', 'integer', 'min:1', 'max:28'],
            'traffic_counted' => ['required', 'string', 'in:both,out,in'],
            'traffic_action' => ['required', 'string', 'in:none,suspend'],
            'usage_cpu_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'usage_memory_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'usage_disk_mb_s' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'usage_network_mbps' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'usage_window_minutes' => ['required', 'integer', 'min:15', 'max:1440'],
            'usage_action' => ['required', 'string', 'in:none,stop,suspend'],
            // Generated root passwords (docs/adr/0038). Affixes use only characters that survive copying and typing.
            'password_length' => ['required', 'integer', 'min:12', 'max:64'],
            'password_characters' => ['required', 'string', 'in:lower,mixed,symbols'],
            'password_prefix' => ['nullable', 'string', 'max:32', Rule::regex('/^[A-Za-z0-9!@#%+=.,:_-]+$/')],
            'password_suffix' => ['nullable', 'string', 'max:32', Rule::regex('/^[A-Za-z0-9!@#%+=.,:_-]+$/')],
        ];
    }

    /**
     * Windows refuses a password with fewer than three kinds of characters
     * (lowercase, uppercase, digits, symbols), so a format with fewer is refused
     * here too. The random part is checked by the generator's own alphabet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['password_characters', 'password_prefix', 'password_suffix'])) {
                return;
            }

            $data = $validator->getData();

            if (PasswordGenerator::kinds((string) $data['password_characters'], (string) ($data['password_prefix'] ?? ''), (string) ($data['password_suffix'] ?? '')) < 3) {
                $validator->errors()->add('password_characters', __('Windows requires at least three kinds of characters (lowercase, uppercase, digits, symbols). Choose more characters, or add a symbol or capital letter to the prefix or suffix.'));
            }
        });
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
            'traffic_reset_day' => (int) $data['traffic_reset_day'],
            'traffic_counted' => (string) $data['traffic_counted'],
            'traffic_action' => (string) $data['traffic_action'],
            'usage_cpu_percent' => $optionalInt('usage_cpu_percent'),
            'usage_memory_percent' => $optionalInt('usage_memory_percent'),
            'usage_disk_mb_s' => $optionalInt('usage_disk_mb_s'),
            'usage_network_mbps' => $optionalInt('usage_network_mbps'),
            'usage_window_minutes' => (int) $data['usage_window_minutes'],
            'usage_action' => (string) $data['usage_action'],
            'password_length' => (int) $data['password_length'],
            'password_characters' => (string) $data['password_characters'],
            'password_prefix' => $optionalString('password_prefix'),
            'password_suffix' => $optionalString('password_suffix'),
        ];
    }
}
