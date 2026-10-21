<?php

declare(strict_types=1);

namespace App\Support\Network;

use Generator;
use InvalidArgumentException;
use LogicException;

/**
 * An IPv4 or IPv6 network in CIDR notation, normalised to its network address.
 */
final readonly class Cidr
{
    private function __construct(
        public string $address,
        public int $prefix,
        public int $version,
        private string $packed,
    ) {}

    public static function parse(string $cidr): self
    {
        $parts = explode('/', trim($cidr));

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            throw new InvalidArgumentException("Invalid CIDR notation: {$cidr}");
        }

        $packed = @inet_pton($parts[0]);

        if ($packed === false) {
            throw new InvalidArgumentException("Invalid IP address in {$cidr}");
        }

        $version = strlen($packed) === 4 ? 4 : 6;
        $prefix = (int) $parts[1];

        if ($prefix > ($version === 4 ? 32 : 128)) {
            throw new InvalidArgumentException("Invalid prefix length in {$cidr}");
        }

        $network = $packed & self::mask($prefix, strlen($packed));

        return new self((string) inet_ntop($network), $prefix, $version, $network);
    }

    public static function isValid(string $cidr): bool
    {
        try {
            self::parse($cidr);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether an entry is a single address or a network in CIDR notation
     * (as Proxmox IP sets take them).
     */
    public static function isAddressOrNetwork(string $entry): bool
    {
        return str_contains($entry, '/') ? self::isValid($entry) : filter_var(trim($entry), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * One spelling per IP set entry, as Proxmox may list one differently
     * from how it was written: networks as "203.0.113.0/24" (host bits
     * cleared), single addresses without a prefix, IPv6 shortened. Invalid
     * entries are returned trimmed.
     */
    public static function canonical(string $entry): string
    {
        $entry = trim($entry);

        if (! str_contains($entry, '/')) {
            $packed = @inet_pton($entry);

            return $packed === false ? $entry : (string) inet_ntop($packed);
        }

        if (! self::isValid($entry)) {
            return $entry;
        }

        $cidr = self::parse($entry);

        return $cidr->prefix === ($cidr->version === 4 ? 32 : 128) ? $cidr->address : (string) $cidr;
    }

    public function contains(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== strlen($this->packed)) {
            return false;
        }

        return ($packed & self::mask($this->prefix, strlen($packed))) === $this->packed;
    }

    /**
     * Number of usable IPv4 host addresses (network and broadcast excluded for /30 and larger).
     */
    public function hostCount(): int
    {
        $this->assertIpv4();

        $size = 2 ** (32 - $this->prefix);

        return $this->prefix >= 31 ? $size : $size - 2;
    }

    /**
     * First and last usable IPv4 host address as integers.
     *
     * @return array{int, int}
     */
    public function hostRange(): array
    {
        $this->assertIpv4();

        $first = (int) ip2long($this->address);
        $last = $first + 2 ** (32 - $this->prefix) - 1;

        return $this->prefix < 31 ? [$first + 1, $last - 1] : [$first, $last];
    }

    /**
     * Usable IPv4 host addresses, in order.
     *
     * @return Generator<int, string>
     */
    public function hosts(): Generator
    {
        [$first, $last] = $this->hostRange();

        for ($ip = $first; $ip <= $last; $ip++) {
            yield (string) long2ip($ip);
        }
    }

    /**
     * The address at a (small, non-negative) offset from the network address,
     * e.g. offset 1 of 2001:db8::/64 is 2001:db8::1.
     */
    public function address(int $offset): string
    {
        $bytes = array_values((array) unpack('C*', $this->packed));
        $carry = $offset;

        for ($i = count($bytes) - 1; $i >= 0 && $carry > 0; $i--) {
            $sum = (int) $bytes[$i] + ($carry & 0xFF);
            $bytes[$i] = $sum & 0xFF;
            $carry = ($carry >> 8) + ($sum >> 8);
        }

        $address = (string) inet_ntop(pack('C*', ...$bytes));

        if ($carry > 0 || ! $this->contains($address)) {
            throw new InvalidArgumentException("Offset {$offset} is outside {$this}.");
        }

        return $address;
    }

    public function __toString(): string
    {
        return "{$this->address}/{$this->prefix}";
    }

    private function assertIpv4(): void
    {
        if ($this->version !== 4) {
            throw new LogicException('Host enumeration is only supported for IPv4 networks.');
        }
    }

    private static function mask(int $prefix, int $bytes): string
    {
        $mask = str_repeat("\xff", intdiv($prefix, 8));

        if ($prefix % 8 !== 0) {
            $mask .= chr((0xFF << (8 - $prefix % 8)) & 0xFF);
        }

        return str_pad($mask, $bytes, "\x00");
    }
}
