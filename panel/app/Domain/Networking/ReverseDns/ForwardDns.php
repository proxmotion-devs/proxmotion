<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

/**
 * Forward-confirmed reverse DNS: does the hostname resolve to the address?
 * Uses the panel host's resolver; tests replace this class.
 */
class ForwardDns
{
    public function resolvesTo(string $hostname, string $address): bool
    {
        $v6 = str_contains($address, ':');
        $records = @dns_get_record($hostname, $v6 ? DNS_AAAA : DNS_A);

        if (! is_array($records)) {
            return false;
        }

        $wanted = inet_pton($address);

        foreach ($records as $record) {
            $ip = $record[$v6 ? 'ipv6' : 'ip'] ?? null;

            if (is_string($ip) && @inet_pton($ip) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
