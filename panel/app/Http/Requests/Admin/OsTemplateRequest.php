<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An OS template is of one version from the administrators' list of
 * operating systems (docs/adr/0026); the system, version and platform are
 * copied from it.
 */
class OsTemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'operating_system_version_id' => ['required', 'integer', Rule::exists('operating_system_versions', 'id')],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'proxmox_template_ids' => ['array'],
            'proxmox_template_ids.*' => ['integer', 'distinct', Rule::exists('proxmox_templates', 'id')],
        ];
    }

    /**
     * The OS template's columns.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        return $this->safe()->except('proxmox_template_ids');
    }
}
