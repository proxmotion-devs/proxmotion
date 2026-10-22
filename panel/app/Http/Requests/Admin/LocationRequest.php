<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Location;
use App\Provisioning\HostnameGenerator;
use App\Rules\NetworkList;
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
            'protected_networks' => ['nullable', 'string', 'max:4000', new NetworkList],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationData(): array
    {
        $data = $this->validated();
        $data['country_code'] = isset($data['country_code']) ? strtoupper((string) $data['country_code']) : null;
        $data['hostname_template'] = isset($data['hostname_template']) ? strtolower(trim((string) $data['hostname_template'])) : null;
        $networks = NetworkList::canonical(isset($data['protected_networks']) ? (string) $data['protected_networks'] : null);
        $data['protected_networks'] = $networks === [] ? null : $networks;

        return $data;
    }
}
