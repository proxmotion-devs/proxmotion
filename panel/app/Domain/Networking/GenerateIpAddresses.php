<?php

declare(strict_types=1);

namespace App\Domain\Networking;

use App\Enums\IpVersion;
use App\Models\IpPool;
use App\Support\Network\Cidr;
use InvalidArgumentException;

/**
 * Adds the usable IPv4 addresses of a pool's whole network (optionally limited
 * to a range). Protected addresses (gateway, node IPs…) and addresses that
 * already exist are skipped. Only for blocks that fully belong to the provider.
 */
final readonly class GenerateIpAddresses
{
    public const int MAX_ADDRESSES = AddressInput::MAX_ADDRESSES;

    public function __construct(private AddIpAddresses $add) {}

    /**
     * @return int number of addresses added
     *
     * @throws InvalidArgumentException for IPv6 pools or more than MAX_ADDRESSES addresses
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

        $addresses = $last >= $first ? array_map(fn (int $ip) => (string) long2ip($ip), range($first, $last)) : [];

        return $this->add->handle($pool, $addresses)['added'];
    }
}
