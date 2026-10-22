<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Firewall\FirewallRule;
use App\Http\Requests\FirewallRulesRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A firewall rule set (docs/adr/0035 §3).
 */
class FirewallRulesetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'default_policy' => ['required', 'in:accept,drop'],
            'is_active' => ['required', 'boolean'],
            ...FirewallRule::validation('rules'),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => FirewallRulesRequest::checkRules($validator, $this->input('rules'), 'rules')];
    }

    /**
     * @return array{name: string, description: string|null, default_policy: string, is_active: bool, rules: list<array{action: string, protocol: string, port: string|null, source: string|null, comment: string|null}>}
     */
    public function rulesetData(): array
    {
        /** @var list<array<string, mixed>> $rules */
        $rules = array_values($this->array('rules'));

        return [
            'name' => $this->string('name')->trim()->toString(),
            'description' => $this->filled('description') ? $this->string('description')->trim()->toString() : null,
            'default_policy' => $this->input('default_policy') === 'accept' ? 'accept' : 'drop',
            'is_active' => $this->boolean('is_active'),
            'rules' => array_map(fn (array $rule) => FirewallRule::fromArray($rule)->toArray(), $rules),
        ];
    }
}
