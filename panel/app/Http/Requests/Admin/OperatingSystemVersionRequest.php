<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\OperatingSystem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A version added to an operating system ("24.04", docs/adr/0026).
 */
class OperatingSystemVersionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $system = $this->route('operatingSystem');

        return [
            'version' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._ -]*$/',
                Rule::unique('operating_system_versions', 'version')->where('operating_system_id', $system instanceof OperatingSystem ? $system->id : 0),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['version' => trim((string) $this->input('version'))]);
    }
}
