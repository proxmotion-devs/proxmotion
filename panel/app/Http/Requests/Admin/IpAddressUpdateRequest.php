<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IpAddressUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_reserved' => ['required', 'boolean'],
            'mac_address' => ['nullable', 'mac_address'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function addressData(): array
    {
        $data = $this->validated();
        $data['mac_address'] = $this->filled('mac_address') ? strtolower($this->string('mac_address')->replace('-', ':')->toString()) : null;

        return $data;
    }
}
