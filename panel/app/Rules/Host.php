<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A hostname (RFC 1123) or an IPv4/IPv6 address, without scheme or port.
 */
class Host implements ValidationRule
{
    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || (filter_var($value, FILTER_VALIDATE_IP) === false && preg_match(self::HOSTNAME, $value) !== 1)) {
            $fail(__('The :attribute must be a hostname or an IP address.'));
        }
    }
}
