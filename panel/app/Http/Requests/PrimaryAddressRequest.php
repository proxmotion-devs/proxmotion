<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\IpVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changing a server's primary address (docs/adr/0011), from the admin server
 * page and the billing API: "address" is one of the server's additional
 * addresses or a free address of its pools; without it, the next free
 * address of "ip_version" becomes the primary.
 */
class PrimaryAddressRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'address' => ['nullable', 'string', 'ip'],
            'ip_version' => ['required_without:address', 'nullable', 'integer', 'in:4,6'],
        ];
    }

    public function address(): ?string
    {
        return $this->filled('address') ? $this->string('address')->trim()->value() : null;
    }

    public function ipVersion(): IpVersion
    {
        $address = $this->address();

        if ($address !== null) {
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? IpVersion::V6 : IpVersion::V4;
        }

        return IpVersion::from($this->integer('ip_version'));
    }
}
