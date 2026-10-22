<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Models\IpPool;

/**
 * One address to add to a server (docs/adr/0028): the next free one, or
 * $address, of the pools the server may use, or only of $pool. With $primary
 * it becomes the primary of its IP version.
 */
final readonly class AddressAddition
{
    public function __construct(
        public IpVersion $version,
        public ?IpPool $pool = null,
        public ?string $address = null,
        public bool $primary = false,
    ) {}
}
