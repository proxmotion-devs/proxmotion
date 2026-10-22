<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

/**
 * Summary of GET /cluster/status. Standalone nodes have no "cluster" entry.
 */
final readonly class ClusterStatus
{
    /**
     * @param  list<string>  $nodes
     * @param  array<string, string>  $nodeAddresses  per node name: its address in the cluster (`ip`), where Proxmox reports one
     */
    public function __construct(
        public ?string $clusterName,
        public bool $quorate,
        public array $nodes,
        public array $nodeAddresses = [],
    ) {}

    public function isCluster(): bool
    {
        return $this->clusterName !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function fromEntries(array $entries): self
    {
        $clusterName = null;
        $quorate = true;
        $nodes = [];
        $addresses = [];

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) === 'cluster') {
                $clusterName = (string) ($entry['name'] ?? '');
                $quorate = (bool) ($entry['quorate'] ?? false);
            } elseif (($entry['type'] ?? null) === 'node') {
                $name = (string) $entry['name'];
                $nodes[] = $name;

                if (is_string($entry['ip'] ?? null) && filter_var($entry['ip'], FILTER_VALIDATE_IP) !== false) {
                    $addresses[$name] = $entry['ip'];
                }
            }
        }

        return new self($clusterName ?: null, $quorate, $nodes, $addresses);
    }
}
