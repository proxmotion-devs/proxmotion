<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The incoming move token of a cluster and its certificate fingerprint
 * (docs/adr/0037 §5). An empty token ID removes the token; an empty secret
 * keeps the saved one.
 */
class IncomingMovesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'incoming_token_id' => ['nullable', 'string', 'max:191', 'regex:/^[^\s@!=,]+@[^\s@!=,]+![^\s@!=,]+$/'],
            'incoming_token_secret' => ['nullable', 'string', 'max:191', 'regex:/^[^\s,]+$/'],
            'tls_fingerprint' => ['nullable', 'string', 'regex:/^([0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'incoming_token_id.regex' => __('Enter the token ID as user@realm!token.'),
            'tls_fingerprint.regex' => __('Enter the SHA-256 fingerprint as 32 hexadecimal pairs separated by colons.'),
        ];
    }
}
