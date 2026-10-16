<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\DnsProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An empty API key on update keeps the stored one (it is never sent back to the browser).
 */
class DnsProviderRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $powerDns = $this->input('driver') === 'powerdns';
        $creating = ! $this->route('dnsProvider') instanceof DnsProvider;

        return [
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(DnsProvider::DRIVERS)],
            'api_url' => [Rule::requiredIf($powerDns), 'nullable', 'url:http,https', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'api_key' => [Rule::requiredIf($powerDns && $creating), 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Apply the input to a (new or existing) provider without saving it.
     */
    public function fillProvider(DnsProvider $provider): DnsProvider
    {
        $powerDns = $this->input('driver') === 'powerdns';

        $provider->fill([
            'name' => $this->string('name')->toString(),
            'driver' => $this->string('driver')->toString(),
            'options' => $powerDns ? [
                'api_url' => $this->string('api_url')->toString(),
                'server_id' => $this->filled('server_id') ? $this->string('server_id')->toString() : 'localhost',
            ] : null,
        ]);

        if (! $powerDns) {
            $provider->credentials = null;
        } elseif ($this->filled('api_key')) {
            $provider->credentials = ['api_key' => $this->string('api_key')->toString()];
        }

        return $provider;
    }
}
