<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

use InvalidArgumentException;

/**
 * The reverse DNS name of an IP address: 5.113.0.203.in-addr.arpa for
 * IPv4, one nibble per label under ip6.arpa for IPv6.
 */
final class PtrName
{
    public static function for(string $address): string
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            throw new InvalidArgumentException("{$address} is not an IP address.");
        }

        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', (string) inet_ntop($packed)))).'.in-addr.arpa';
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))).'.ip6.arpa';
    }

    /**
     * Whether the name lies in the zone (the zone itself or below it).
     */
    public static function inZone(string $name, string $zone): bool
    {
        $name = strtolower(rtrim($name, '.'));
        $zone = strtolower(rtrim($zone, '.'));

        return $zone !== '' && ($name === $zone || str_ends_with($name, ".{$zone}"));
    }
}
