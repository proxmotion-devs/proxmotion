<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\OsPlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OsTemplateRequest extends FormRequest
{
    public const array FAMILIES = ['ubuntu', 'debian', 'almalinux', 'rocky', 'centos', 'fedora', 'opensuse', 'archlinux', 'windows', 'other'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'family' => ['required', Rule::in(self::FAMILIES)],
            'version' => ['required', 'string', 'max:32'],
            // A derivative's os-release ID (docs/adr/0022): lowercase letters,
            // digits, ".", "_" and "-", and never a system on the family list.
            'distribution' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::notIn(self::FAMILIES), 'prohibited_if:family,windows'],
            'distribution_version' => ['nullable', 'string', 'max:32'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'proxmox_template_ids' => ['array'],
            'proxmox_template_ids.*' => ['integer', 'distinct', Rule::exists('proxmox_templates', 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $distribution = trim((string) $this->input('distribution'));
        $version = trim((string) $this->input('distribution_version'));

        $this->merge([
            'distribution' => $distribution === '' ? null : strtolower($distribution),
            'distribution_version' => $version === '' ? null : $version,
        ]);
    }

    /**
     * The OS template's columns. The platform (which default guest scripts
     * run) follows the family; administrators no longer pick it. Without a
     * distribution the template is its base system and has no own version.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        $data = $this->safe()->except('proxmox_template_ids');
        $data['platform'] = $data['family'] === 'windows' ? OsPlatform::Windows : OsPlatform::Linux;
        $data['distribution'] ??= null;
        $data['distribution_version'] = $data['distribution'] === null ? null : ($data['distribution_version'] ?? null);

        return $data;
    }
}
