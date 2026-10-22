<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;

/**
 * An edit of a server's addresses (docs/adr/0028): addresses to add, addresses
 * of the server to remove, and per IP version the address that becomes the
 * primary (one the server keeps or a specific address added here).
 */
final readonly class AddressChange
{
    /**
     * @param  list<AddressAddition>  $add
     * @param  list<string>  $remove
     * @param  array<int, string>  $primary  IP version (4 or 6) => address
     */
    public function __construct(
        public array $add = [],
        public array $remove = [],
        public array $primary = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->add === [] && $this->remove === [] && $this->primary === [];
    }

    public function primaryOf(IpVersion $version): ?string
    {
        $address = $this->primary[$version->value] ?? null;

        return $address === null ? null : PrimaryAddressChanger::normalize($address);
    }
}
