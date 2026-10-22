<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * What GET /nodes/{node}/qemu/{vmid}/migrate reports before a VM moves to
 * another node of its cluster (docs/adr/0037 §2).
 */
final readonly class MigrationPreconditions
{
    /**
     * @param  list<string>  $localResources  PCI, USB and other devices that keep the VM on its node
     * @param  array<string, list<string>>  $notAllowedNodes  per node: the storages it lacks
     */
    public function __construct(
        public bool $running,
        public array $localResources,
        public array $notAllowedNodes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $notAllowed = [];

        foreach (is_array($data['not_allowed_nodes'] ?? null) ? $data['not_allowed_nodes'] : [] as $node => $reasons) {
            $storages = is_array($reasons) && is_array($reasons['unavailable_storages'] ?? null) ? $reasons['unavailable_storages'] : [];
            $notAllowed[(string) $node] = array_values(array_map(strval(...), array_filter($storages, is_scalar(...))));
        }

        return new self(
            running: (bool) ($data['running'] ?? false),
            localResources: array_values(array_map(strval(...), array_filter(is_array($data['local_resources'] ?? null) ? $data['local_resources'] : [], is_scalar(...)))),
            notAllowedNodes: $notAllowed,
        );
    }

    /**
     * Why Proxmox will not move the VM to $node, or null.
     */
    public function refusal(string $node): ?string
    {
        if ($this->localResources !== []) {
            return 'The VM uses local devices ('.implode(', ', $this->localResources).') that keep it on its node.';
        }

        if (array_key_exists($node, $this->notAllowedNodes)) {
            $storages = $this->notAllowedNodes[$node];

            return $storages === []
                ? "Proxmox does not allow moving the VM to {$node}."
                : "The node {$node} lacks the storage ".implode(', ', $storages).' the VM uses.';
        }

        return null;
    }
}
