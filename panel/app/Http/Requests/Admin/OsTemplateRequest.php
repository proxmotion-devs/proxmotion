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
 * An OS template names its distribution (os-release ID) and version. For a
 * distribution the catalog knows, the family and the system apps are
 * matched as follow from the catalog (Pardus 23 as Debian 12); one it does
 * not know is of the family "other" unless a check against a server found
 * its family (docs/adr/0024).
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
            'family' => $known ? ['nullable'] : ['nullable', Rule::in(self::CUSTOM_FAMILIES)],
            'compatible_with' => ['nullable', Rule::in(Distributions::bases())],
            'compatible_version' => $known ? ['nullable', 'string', 'max:32'] : ['nullable', 'required_with:compatible_with', 'string', 'max:32'],
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

            // A known derivative's base version comes from the catalog's releases.
            if (Distributions::base($distribution) !== null && $this->input('compatible_version') === null) {
                $validator->errors()->add('version', __('Pick a release of :name from the list.', ['name' => Distributions::name($distribution)]));
            }

            if ($compatible !== null && ! Distributions::known($distribution) && Distributions::family((string) $compatible) !== $this->family()) {
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

        // A known distribution is matched as the catalog says (Pardus 23 as
        // Debian 12, RHEL 9 as AlmaLinux 9, Debian as itself).
        if (Distributions::known($distribution)) {
            $compatible = Distributions::base($distribution);
            $compatibleVersion = $compatible === null ? null : Distributions::baseVersion($distribution, (string) $text('version'));
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
        return Distributions::family((string) $this->input('distribution')) ?? OsFamily::tryFrom((string) $this->input('family')) ?? OsFamily::Other;
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
