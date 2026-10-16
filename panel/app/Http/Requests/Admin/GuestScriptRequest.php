<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Servers\GuestScripts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GuestScriptRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'script' => [
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
}
