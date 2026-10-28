<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspending a server from its admin page, with an optional reason shown on
 * the page until it is unsuspended (the billing API takes the same reason).
 */
class SuspendServerRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->string('reason')->trim()->value();

        return $reason === '' ? null : $reason;
    }
}
