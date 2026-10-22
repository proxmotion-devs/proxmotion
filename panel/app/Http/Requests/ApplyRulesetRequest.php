<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FirewallRuleset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Apply an active firewall rule set to a server (docs/adr/0035 §3): its
 * rules replace the server's or follow them.
 */
class ApplyRulesetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ruleset' => ['required', 'string', Rule::exists('firewall_rulesets', 'uuid')->where('is_active', true)],
            'mode' => ['required', Rule::in(['replace', 'append'])],
        ];
    }

    public function ruleset(): FirewallRuleset
    {
        return FirewallRuleset::query()->where('uuid', $this->string('ruleset'))->firstOrFail();
    }

    /**
     * @return 'replace'|'append'
     */
    public function mode(): string
    {
        return $this->input('mode') === 'append' ? 'append' : 'replace';
    }
}
