<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NodeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_maintenance' => ['required', 'boolean'],
            'cpu_overcommit' => ['required', 'numeric', 'between:0.1,32'],
            'memory_overcommit' => ['required', 'numeric', 'between:0.1,4'],
            'disk_overcommit' => ['required', 'numeric', 'between:0.1,10'],
            'max_servers' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
