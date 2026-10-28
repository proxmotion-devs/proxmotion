<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Servers\PasswordGenerator;
use App\Domain\Settings\PanelSettings;
use App\Rules\Hostname;
use App\Rules\NetworkList;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One page of Panel settings (docs/adr/0038): every setting of its category
 * (PanelSettings::CATEGORIES) is sent and saved; empty optional fields arrive
 * as null.
 */
class PanelSettingsRequest extends FormRequest
{
    /** Further notification recipients besides the administrators (docs/adr/0044). */
    private const int MAX_RECIPIENTS = 10;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_intersect_key([
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
            // Storage health (docs/adr/0047).
            'storage_warning_percent' => ['required', 'integer', 'min:50', 'max:99'],
            'storage_critical_percent' => ['required', 'integer', 'min:51', 'max:100', 'gt:storage_warning_percent'],
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
            // Email notifications (docs/adr/0044): recipients one per line or comma-separated.
            'notifications_enabled' => ['required', 'boolean'],
            'notification_recipients' => ['nullable', 'string', 'max:2550', function (string $attribute, mixed $value, Closure $fail) {
                $addresses = self::addresses((string) $value);

                if (count($addresses) > self::MAX_RECIPIENTS) {
                    $fail(__('Enter at most :count addresses.', ['count' => self::MAX_RECIPIENTS]));
                }

                foreach ($addresses as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $fail(__(':address is not a valid email address.', ['address' => $address]));

                        return;
                    }
                }
            }],
            'notification_throttle_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ], array_flip($this->fields()));
    }

    /**
     * Email addresses entered one per line or comma-separated, lowercase, each once.
     *
     * @return list<string>
     */
    private static function addresses(string $value): array
    {
        $addresses = preg_split('/[\s,;]+/', strtolower($value), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($addresses === false ? [] : $addresses));
    }

    /**
     * The settings of the page's category.
     *
     * @return list<string>
     */
    private function fields(): array
    {
        return PanelSettings::CATEGORIES[(string) $this->route('category')] ?? [];
    }

    /**
     * Windows refuses a password with fewer than three kinds of characters
     * (lowercase, uppercase, digits, symbols), so a format with fewer is refused
     * here too. The random part is checked by the generator's own alphabet.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! in_array('password_characters', $this->fields(), true) || $validator->errors()->hasAny(['password_characters', 'password_prefix', 'password_suffix'])) {
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

        $values = [
            'cross_cluster_moves' => fn () => (bool) $data['cross_cluster_moves'],
            'move_deadline_minutes' => fn () => (int) $data['move_deadline_minutes'],
            'move_bwlimit_kib' => fn () => $optionalInt('move_bwlimit_kib'),
            'min_vmid' => fn () => (int) $data['min_vmid'],
            'provisioning_deadline_minutes' => fn () => (int) $data['provisioning_deadline_minutes'],
            'verify_dns_name' => fn () => ($name = $optionalString('verify_dns_name')) === null ? null : strtolower($name),
            'protected_networks' => fn () => NetworkList::canonical($optionalString('protected_networks')),
            'drift_stopped_grace_minutes' => fn () => (int) $data['drift_stopped_grace_minutes'],
            'storage_warning_percent' => fn () => (int) $data['storage_warning_percent'],
            'storage_critical_percent' => fn () => (int) $data['storage_critical_percent'],
            'console_max_minutes' => fn () => (int) $data['console_max_minutes'],
            'console_idle_minutes' => fn () => (int) $data['console_idle_minutes'],
            'docs_url' => fn () => $optionalString('docs_url'),
            'traffic_reset_day' => fn () => (int) $data['traffic_reset_day'],
            'traffic_counted' => fn () => (string) $data['traffic_counted'],
            'traffic_action' => fn () => (string) $data['traffic_action'],
            'usage_cpu_percent' => fn () => $optionalInt('usage_cpu_percent'),
            'usage_memory_percent' => fn () => $optionalInt('usage_memory_percent'),
            'usage_disk_mb_s' => fn () => $optionalInt('usage_disk_mb_s'),
            'usage_network_mbps' => fn () => $optionalInt('usage_network_mbps'),
            'usage_window_minutes' => fn () => (int) $data['usage_window_minutes'],
            'usage_action' => fn () => (string) $data['usage_action'],
            'password_length' => fn () => (int) $data['password_length'],
            'password_characters' => fn () => (string) $data['password_characters'],
            'password_prefix' => fn () => $optionalString('password_prefix'),
            'password_suffix' => fn () => $optionalString('password_suffix'),
            'notifications_enabled' => fn () => (bool) $data['notifications_enabled'],
            'notification_recipients' => fn () => self::addresses((string) ($data['notification_recipients'] ?? '')),
            'notification_throttle_minutes' => fn () => (int) $data['notification_throttle_minutes'],
        ];

        // Only the page's category: the other settings keep their values.
        return array_map(fn (Closure $value): mixed => $value(), array_intersect_key($values, array_flip($this->fields())));
    }
}
