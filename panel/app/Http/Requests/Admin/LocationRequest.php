<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Location;
use App\Provisioning\HostnameGenerator;
use App\Support\Network\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                Rule::unique('locations', 'code')->ignore($location instanceof Location ? $location->id : null),
            ],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'description' => ['nullable', 'string', 'max:255'],
            // Names servers ordered without a hostname (docs/adr/0031).
            'hostname_template' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail) {
                $problem = HostnameGenerator::problem(strtolower(trim((string) $value)), $this->string('code')->toString());

                if ($problem !== null) {
                    $fail(__($problem));
                }
            }],
            // One address or CIDR per line (docs/adr/0034).
            'protected_networks' => ['nullable', 'string', 'max:4000', function (string $attribute, mixed $value, Closure $fail) {
                foreach (self::lines((string) $value) as $entry) {
                    if (! Cidr::isAddressOrNetwork($entry)) {
                        $fail(__(':entry is not an address or a network in CIDR notation.', ['entry' => $entry]));

                        return;
                    }
                }

                if (count(self::lines((string) $value)) > 100) {
                    $fail(__('Enter at most 100 protected networks.'));
                }
            }],
        ];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), fn (string $line) => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function locationData(): array
    {
        $data = $this->validated();
        $data['country_code'] = isset($data['country_code']) ? strtoupper((string) $data['country_code']) : null;
        $data['hostname_template'] = isset($data['hostname_template']) ? strtolower(trim((string) $data['hostname_template'])) : null;
        $networks = array_values(array_unique(array_map(Cidr::canonical(...), self::lines((string) ($data['protected_networks'] ?? '')))));
        $data['protected_networks'] = $networks === [] ? null : $networks;

        return $data;
    }
}
