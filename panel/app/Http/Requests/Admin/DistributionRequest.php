<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Infrastructure\Distributions;
use App\Enums\OsFamily;
use App\Models\Distribution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A distribution administrators offer OS templates of (docs/adr/0025). Its
 * os-release ID is fixed once saved: templates refer to it.
 */
class DistributionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $current = $this->distribution();

        return [
            'slug' => $current === null
                ? ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9._-]*$/', Rule::unique('distributions', 'slug')]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:100'],
            'family' => ['required', Rule::enum(OsFamily::class)],
            'base' => ['nullable', Rule::in(array_values(array_diff(Distributions::bases(), [$current?->slug])))],
            'same_version' => ['boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->input('base') === null) {
                return;
            }

            if (Distributions::family((string) $this->input('base'))?->value !== $this->input('family')) {
                $validator->errors()->add('base', __('The system it is built on must be of the same family.'));
            }

            // Others built on this one would be matched as a derivative.
            $current = $this->distribution();

            if ($current !== null && Distribution::query()->where('base', $current->slug)->exists()) {
                $validator->errors()->add('base', __('Other systems are built on :name.', ['name' => $current->name]));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $base = trim((string) $this->input('base'));

        $this->merge([
            'base' => $base === '' ? null : $base,
            'same_version' => $base !== '' && $this->boolean('same_version'),
        ]);

        if ($this->distribution() === null) {
            $this->merge(['slug' => strtolower(trim((string) $this->input('slug')))]);
        }
    }

    public function distribution(): ?Distribution
    {
        $distribution = $this->route('distribution');

        return $distribution instanceof Distribution ? $distribution : null;
    }

    /**
     * @return array{name: string, family: string, base: string|null, same_version: bool}
     */
    public function values(): array
    {
        return [
            'name' => (string) $this->input('name'),
            'family' => (string) $this->input('family'),
            'base' => $this->input('base'),
            'same_version' => $this->boolean('same_version'),
        ];
    }
}
