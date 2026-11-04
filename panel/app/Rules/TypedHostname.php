<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A server's hostname typed again to confirm an action on it (AGENTS.md
 * §7.8): trimmed and compared without case, like the admin dialogs do.
 */
class TypedHostname implements ValidationRule
{
    public function __construct(private readonly string $hostname) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strcasecmp(trim($value), $this->hostname) !== 0) {
            $fail(__('Type the hostname of the server to confirm.'));
        }
    }
}
