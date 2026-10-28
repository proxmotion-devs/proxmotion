<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Servers\GuestScripts;
use App\Enums\GuestScriptEvent;
use App\Models\CustomScript;
use App\Models\OsTemplate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A custom script's name and triggers (docs/adr/0043); creating one may also
 * bring its first draft.
 */
class CustomScriptRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $template = $this->route('osTemplate');
        $script = $this->route('customScript');

        return [
            'name' => [
                'required', 'string', 'max:64',
                // Letters, digits, spaces and . _ - ( ): it names a task step and a log.
                'regex:/^[\pL\pN][\pL\pN ._()\-]*$/u',
                Rule::unique('custom_scripts', 'name')
                    ->where('os_template_id', $template instanceof OsTemplate ? $template->id : null)
                    ->ignore($script instanceof CustomScript ? $script->id : null),
            ],
            'manual' => ['required', 'boolean'],
            'events' => ['present', 'array'],
            'events.*' => ['distinct', Rule::enum(GuestScriptEvent::class)],
            // The first draft, only when creating.
            'script' => $script instanceof CustomScript ? ['prohibited'] : [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (is_string($value) && strlen($this->script()) > GuestScripts::MAX_SCRIPT_BYTES) {
                        $fail(__('The script may be at most :max bytes.', ['max' => GuestScripts::MAX_SCRIPT_BYTES]));
                    }
                },
            ],
        ];
    }

    /**
     * The script with Unix line endings; PowerShell reads those as well.
     */
    public function script(): string
    {
        return str_replace("\r\n", "\n", $this->string('script')->value());
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        // In the order of GuestScriptEvent, so the audit log compares lists.
        $chosen = $this->array('events');

        return array_values(array_map(
            fn (GuestScriptEvent $event) => $event->value,
            array_filter(GuestScriptEvent::cases(), fn (GuestScriptEvent $event) => in_array($event->value, $chosen, true)),
        ));
    }
}
