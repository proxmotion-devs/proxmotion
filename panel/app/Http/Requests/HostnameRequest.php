<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\Hostname;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming a server (docs/adr/0012), from the admin server page and the
 * billing API. "reboot" restarts a Windows server so the name applies now.
 */
class HostnameRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            'reboot' => ['sometimes', 'boolean'],
        ];
    }

    public function newHostname(): string
    {
        return $this->string('hostname')->trim()->lower()->value();
    }

    public function reboot(): bool
    {
        return $this->boolean('reboot');
    }
}
