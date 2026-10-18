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
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'proxmox_template_ids' => ['array'],
            'proxmox_template_ids.*' => ['integer', 'distinct', Rule::exists('proxmox_templates', 'id')],
        ];
    }

    /**
     * The OS template's columns. The platform (which default guest scripts
     * run) follows the family; administrators no longer pick it.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        $data = $this->safe()->except('proxmox_template_ids');
        $data['platform'] = $data['family'] === 'windows' ? OsPlatform::Windows : OsPlatform::Linux;

        return $data;
    }
}
