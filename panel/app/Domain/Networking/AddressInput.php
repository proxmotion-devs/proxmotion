<?php

declare(strict_types=1);

namespace App\Domain\Networking;

use App\Enums\IpVersion;
use App\Support\Network\Cidr;

/**
 * Parses what an admin types into "add addresses": single IPs, lists
 * (comma, space or newline separated), IPv4 ranges ("a-b") and IPv4 blocks
 * ("a/29"). Everything is normalised; nothing is written.
 */
final class AddressInput
{
    /**
     * Upper bound per request, so a typo can't create millions of rows.
     */
    public const int MAX_ADDRESSES = 4096;

    /**
     * @param  list<string>  $addresses  normalised, unique, in input order
     * @param  list<string>  $invalid  tokens that are not an address of the pool's IP version
     * @param  list<string>  $outside  addresses outside the pool's network
     */
    private function __construct(
        public readonly array $addresses,
        public readonly array $invalid,
        public readonly array $outside,
        public readonly bool $tooMany,
    ) {}

    /**
     * Human-readable problems with the input (translated), empty when it can be added.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->tooMany) {
            $problems[] = __('Add at most :max addresses at once.', ['max' => (string) self::MAX_ADDRESSES]);
        }

        if ($this->invalid !== []) {
            $problems[] = __('Not valid addresses: :list', ['list' => self::summary($this->invalid)]);
        }

        if ($this->outside !== []) {
            $problems[] = __('Outside the pool network: :list', ['list' => self::summary($this->outside)]);
        }

        if ($problems === [] && $this->addresses === []) {
            $problems[] = __('Enter at least one address.');
        }

        return $problems;
    }

    /**
     * @param  list<string>  $items
     */
    private static function summary(array $items): string
    {
        $shown = implode(', ', array_slice($items, 0, 5));

        return count($items) > 5 ? $shown.' …' : $shown;
    }

    public static function parse(string $input, IpVersion $version, ?Cidr $network): self
    {
        $addresses = [];
        $invalid = [];
        $outside = [];
        $tooMany = false;

        $tokens = preg_split('/[\s,;]+/', trim($input), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $expanded = self::expand($token, $version);

            if ($expanded === null) {
                $invalid[] = $token;

                continue;
            }

            foreach ($expanded as $address) {
                if ($network !== null && ! $network->contains($address)) {
                    $outside[] = $address;
                } else {
                    $addresses[$address] = true;
                }

                if (count($addresses) > self::MAX_ADDRESSES) {
                    $tooMany = true;

                    break 2;
                }
            }
        }

        return new self(array_keys($addresses), $invalid, array_values(array_unique($outside)), $tooMany);
    }

    /**
     * @return list<string>|null null when the token is not valid for this IP version
     */
    private static function expand(string $token, IpVersion $version): ?array
    {
        $flag = $version === IpVersion::V4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;

        if (filter_var($token, FILTER_VALIDATE_IP, $flag) !== false) {
            return [(string) inet_ntop((string) inet_pton($token))];
        }

        if ($version !== IpVersion::V4) {
            return null; // ranges and blocks are IPv4 only
        }

        // Range: 203.0.113.10-203.0.113.20 (or the short form 203.0.113.10-20)
        if (preg_match('/^([\d.]+)-([\d.]+)$/', $token, $match) === 1) {
            $end = str_contains($match[2], '.') ? $match[2] : preg_replace('/\d+$/', $match[2], $match[1]);
            $from = ip2long($match[1]);
            $to = ip2long((string) $end);

            if ($from === false || $to === false || $from > $to || $to - $from >= self::MAX_ADDRESSES) {
                return null;
            }

            return array_map(fn (int $ip) => (string) long2ip($ip), range($from, $to));
        }

        // Block: 203.0.113.144/29 — every address of the block (a sub-block of the pool's network)
        if (str_contains($token, '/') && Cidr::isValid($token)) {
            $block = Cidr::parse($token);

            if ($block->version !== 4 || $block->prefix < 20) {
                return null;
            }

            $first = (int) ip2long($block->address);

            return array_map(fn (int $ip) => (string) long2ip($ip), range($first, $first + 2 ** (32 - $block->prefix) - 1));
        }

        return null;
    }
}
