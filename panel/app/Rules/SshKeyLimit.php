<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Servers\ServerSshKeys;
use App\Support\Ssh\PublicKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * The SSH keys of one server fit its limits (docs/adr/0049 §12): at most the
 * number set on Panel settings, and all together small enough for the
 * guest agent to write them. Takes one key per line, or a list of such
 * texts; lines that are no key are left to SshPublicKeys.
 */
class SshKeyLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $lines = array_filter(is_array($value) ? $value : [$value], is_string(...));

        try {
            $keys = PublicKey::parseMany(implode("\n", $lines));
        } catch (InvalidArgumentException) {
            return;
        }

        $problem = ServerSshKeys::problem($keys);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
