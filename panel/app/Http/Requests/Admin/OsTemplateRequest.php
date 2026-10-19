<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Infrastructure\Distributions;
use App\Enums\OsFamily;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An OS template names its distribution (os-release ID) and version; the
 * family follows from the catalog, and only a distribution the catalog does
 * not know asks for it. A distribution with its own version numbers names
 * the one apps are matched as (docs/adr/0024).
 */
class OsTemplateRequest extends FormRequest
{
    /** Families an administrator may pick for a distribution outside the catalog. */
    public const array CUSTOM_FAMILIES = ['debian', 'rhel', 'suse', 'arch', 'other'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $known = Distributions::known((string) $this->input('distribution'));

        return [
            'name' => ['required', 'string', 'max:100'],
            'distribution' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'version' => ['required', 'string', 'max:32'],
            'family' => $known ? ['nullable'] : ['required', Rule::in(self::CUSTOM_FAMILIES)],
            'compatible_with' => ['nullable', Rule::in(Distributions::bases())],
            'compatible_version' => ['nullable', 'required_with:compatible_with', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'proxmox_template_ids' => ['array'],
            'proxmox_template_ids.*' => ['integer', 'distinct', Rule::exists('proxmox_templates', 'id')],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $distribution = (string) $this->input('distribution');
            $compatible = $this->input('compatible_with');

            if (Distributions::base($distribution) !== null && $compatible === null) {
                $validator->errors()->add('compatible_with', __('Choose the system :name is compatible with.', ['name' => Distributions::name($distribution)]));
            }

            if ($compatible === null) {
                return;
            }

            if (Distributions::known($distribution) && Distributions::base($distribution) === null) {
                $validator->errors()->add('compatible_with', __(':name is matched as itself.', ['name' => Distributions::name($distribution)]));
            } elseif (Distributions::family((string) $compatible) !== $this->family()) {
                $validator->errors()->add('compatible_with', __('The compatible system must be of the same family.'));
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key): ?string => trim((string) $this->input($key)) === '' ? null : trim((string) $this->input($key));
        $distribution = strtolower((string) $text('distribution'));
        $compatible = $text('compatible_with');
        $compatibleVersion = $text('compatible_version');

        // RHEL 9 is AlmaLinux 9: the version is the same unless one is given.
        if ($compatible !== null && $compatibleVersion === null && Distributions::sameVersion($distribution)) {
            $compatibleVersion = $text('version');
        }

        $this->merge([
            'distribution' => $distribution,
            'family' => $text('family'),
            'compatible_with' => $compatible === null ? null : strtolower($compatible),
            'compatible_version' => $compatible === null ? null : $compatibleVersion,
        ]);
    }

    /** The template's family: from the catalog, or as chosen for an unknown distribution. */
    public function family(): OsFamily
    {
        return Distributions::family((string) $this->input('distribution')) ?? OsFamily::from((string) $this->input('family'));
    }

    /**
     * The OS template's columns (the platform follows the family in OsTemplate).
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        $data = $this->safe()->except('proxmox_template_ids');
        $data['family'] = $this->family();
        $data['compatible_with'] ??= null;
        $data['compatible_version'] = $data['compatible_with'] === null ? null : $data['compatible_version'];

        return $data;
    }
}
