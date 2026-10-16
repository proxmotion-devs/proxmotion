<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Ssh\PublicKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Public SSH keys in OpenSSH format, one per line (or exactly one with $single).
 */
class SshPublicKeys implements ValidationRule
{
    public function __construct(private readonly bool $single = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('Each line must be a public SSH key, e.g. "ssh-ed25519 AAAA… user@host".'));

            return;
        }

        try {
            $keys = PublicKey::parseMany($value);
        } catch (InvalidArgumentException $e) {
            $fail(__($e->getMessage()));

            return;
        }

        if ($this->single && count($keys) !== 1) {
            $fail(__('Paste exactly one public SSH key.'));
        }
    }
}
