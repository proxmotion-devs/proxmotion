<?php

declare(strict_types=1);

namespace App\Domain\Networking;

use App\Enums\IpVersion;
use App\Models\IpPool;
use App\Support\Network\Cidr;
use InvalidArgumentException;

/**
 * Adds the usable IPv4 addresses of a pool's network (optionally limited to a range)
 * as individual IP addresses. Existing addresses and the gateway are skipped.
 */
final class GenerateIpAddresses
{
    /**
     * Upper bound for one call, so a typo like /8 can't create millions of rows.
     */
    public const int MAX_ADDRESSES = 4096;

    /**
     * @return int number of addresses added
     *
     * @throws InvalidArgumentException for IPv6 pools or ranges larger than MAX_ADDRESSES
     */
    public function handle(IpPool $pool, ?string $from = null, ?string $to = null): int
    {
        if ($pool->version !== IpVersion::V4) {
            throw new InvalidArgumentException('Addresses can only be generated for IPv4 pools.');
        }

        [$first, $last] = Cidr::parse($pool->network)->hostRange();
        $first = $from !== null ? max($first, (int) ip2long($from)) : $first;
        $last = $to !== null ? min($last, (int) ip2long($to)) : $last;

        if ($last - $first + 1 > self::MAX_ADDRESSES) {
            throw new InvalidArgumentException(sprintf('Refusing to add more than %d addresses at once.', self::MAX_ADDRESSES));
        }

        $gateway = $pool->gateway !== null ? ip2long($pool->gateway) : false;
        $now = now();
        $rows = [];

        for ($ip = $first; $ip <= $last; $ip++) {
            if ($ip !== $gateway) {
                $rows[] = [
                    'ip_pool_id' => $pool->id,
                    'address' => (string) long2ip($ip),
                    'is_reserved' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $added = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $added += $pool->addresses()->getQuery()->insertOrIgnore($chunk);
        }

        return $added;
    }
}
