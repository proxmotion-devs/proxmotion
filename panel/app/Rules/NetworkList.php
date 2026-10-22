<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Network\Cidr;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Addresses and networks in CIDR notation, one per line or comma-separated,
 * at most 100 (protected networks, docs/adr/0034).
 */
class NetworkList implements ValidationRule
{
    public const int MAX = 100;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $entries = self::lines(is_string($value) ? $value : '');

        foreach ($entries as $entry) {
            if (! Cidr::isAddressOrNetwork($entry)) {
                $fail(__(':entry is not an address or a network in CIDR notation.', ['entry' => $entry]));

                return;
            }
        }

        if (count($entries) > self::MAX) {
            $fail(__('Enter at most 100 protected networks.'));
        }
    }

    /**
     * The entries of a validated value, canonical and without repeats.
     *
     * @return list<string>
     */
    public static function canonical(?string $value): array
    {
        return array_values(array_unique(array_map(Cidr::canonical(...), self::lines($value ?? ''))));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []), fn (string $line) => $line !== ''));
    }
}
