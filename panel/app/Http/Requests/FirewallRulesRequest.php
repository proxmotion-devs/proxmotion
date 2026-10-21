<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Firewall\FirewallRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A server's firewall as the admin page and the billing API send it
 * (docs/adr/0035): on or off and the whole list of rules.
 */
class FirewallRulesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            ...FirewallRule::validation('rules'),
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::checkRules($validator, $this->input('rules'), 'rules')];
    }

    /**
     * Problems across the fields of each rule.
     */
    public static function checkRules(Validator $validator, mixed $rules, string $key): void
    {
        foreach (is_array($rules) ? $rules : [] as $i => $rule) {
            $problem = is_array($rule) ? FirewallRule::problem($rule) : null;

            if ($problem !== null && ! $validator->errors()->has("{$key}.{$i}.port")) {
                $validator->errors()->add("{$key}.{$i}.port", __($problem));
            }
        }
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    /**
     * @return list<FirewallRule>
     */
    public function firewallRules(): array
    {
        /** @var list<array<string, mixed>> $rules */
        $rules = array_values($this->array('rules'));

        return array_map(fn (array $rule) => FirewallRule::fromArray($rule), $rules);
    }
}
