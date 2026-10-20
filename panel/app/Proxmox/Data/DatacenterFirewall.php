<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * The options of GET /cluster/firewall/options that decide whether VM
 * firewalls filter anything (docs/adr/0033). Proxmox leaves out options that
 * keep their default: the firewall is off unless enabled, ebtables is on.
 */
final readonly class DatacenterFirewall
{
    public function __construct(
        public bool $enabled,
        public bool $ebtables,
    ) {}

    /**
     * @param  array<array-key, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            enabled: (bool) (int) ($options['enable'] ?? 0),
            ebtables: (bool) (int) ($options['ebtables'] ?? 1),
        );
    }
}
