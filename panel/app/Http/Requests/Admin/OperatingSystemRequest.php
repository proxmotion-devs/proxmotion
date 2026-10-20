<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\OsPlatform;
use App\Models\OperatingSystem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An operating system of the administrators' list (docs/adr/0026): a name
 * and Linux or Windows. The platform of a system with OS templates stays,
 * since their servers' guest scripts depend on it.
 */
class OperatingSystemRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $current = $this->route('operatingSystem');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('operating_systems', 'name')->ignore($current instanceof OperatingSystem ? $current->id : null)],
            'platform' => ['required', Rule::enum(OsPlatform::class)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $current = $this->route('operatingSystem');

            if ($validator->errors()->isEmpty()
                && $current instanceof OperatingSystem
                && $current->platform->value !== $this->input('platform')
                && $current->versions()->whereHas('osTemplates')->exists()) {
                $validator->errors()->add('platform', __('OS templates use this system, so its type cannot change.'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
