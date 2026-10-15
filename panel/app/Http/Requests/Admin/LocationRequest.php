<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Location;
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationData(): array
    {
        $data = $this->validated();
        $data['country_code'] = isset($data['country_code']) ? strtoupper((string) $data['country_code']) : null;

        return $data;
    }
}
