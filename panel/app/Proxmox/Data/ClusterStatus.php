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
     */
    public function __construct(
        public ?string $clusterName,
        public bool $quorate,
        public array $nodes,
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

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) === 'cluster') {
                $clusterName = (string) ($entry['name'] ?? '');
                $quorate = (bool) ($entry['quorate'] ?? false);
            } elseif (($entry['type'] ?? null) === 'node') {
                $nodes[] = (string) $entry['name'];
            }
        }

        return new self($clusterName ?: null, $quorate, $nodes);
    }
}
