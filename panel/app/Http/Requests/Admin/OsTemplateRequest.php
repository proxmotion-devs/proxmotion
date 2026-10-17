<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\OsPlatform;
use App\Enums\TemplateCapability;
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
            'platform' => ['sometimes', 'required', Rule::enum(OsPlatform::class)],
            // What app recipes may rely on (docs/adr/0016 §3); null: the family's defaults.
            'capabilities' => ['sometimes', 'nullable', 'array'],
            'capabilities.*' => ['distinct', Rule::enum(TemplateCapability::class)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'proxmox_template_ids' => ['array'],
            'proxmox_template_ids.*' => ['integer', 'distinct', Rule::exists('proxmox_templates', 'id')],
        ];
    }
}
