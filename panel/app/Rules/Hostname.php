<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A DNS hostname usable as a Proxmox VM name and cloud-init hostname:
 * labels of letters, digits and dashes, not starting or ending with a dash.
 */
class Hostname implements ValidationRule
{
    private const PATTERN = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail(__('The :attribute must be a valid hostname, e.g. web1.example.com.'));
        }
    }
}
