<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * One entry of GET /nodes/{node}/qemu/{vmid}/agent/network-get-interfaces
 * (the guest agent's guest-network-get-interfaces).
 */
final readonly class GuestNetworkInterface
{
    /**
     * @param  list<string>  $addresses  IPv4 and IPv6 addresses without prefix length
     */
    public function __construct(
        public string $name,
        /** Lower case, e.g. bc:24:11:aa:bb:cc; null for interfaces without one (loopback on some guests). */
        public ?string $mac,
        public array $addresses,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $addresses = [];

        foreach (is_array($data['ip-addresses'] ?? null) ? $data['ip-addresses'] : [] as $address) {
            if (is_array($address) && is_string($address['ip-address'] ?? null)) {
                // Windows reports IPv6 link-local addresses with a zone index ("fe80::1%4").
                $addresses[] = explode('%', $address['ip-address'])[0];
            }
        }

        $mac = is_string($data['hardware-address'] ?? null) ? strtolower($data['hardware-address']) : null;

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            mac: $mac === '' ? null : $mac,
            addresses: $addresses,
        );
    }
}
