<?php

declare(strict_types=1);

namespace App\Provisioning\Placement;

use App\Models\Node;
use App\Models\PlacementGroup;
use Illuminate\Support\Collection;

/**
 * Limits on where a new server may go, on top of the location.
 */
final readonly class PlacementRules
{
    /**
     * @param  list<int>  $clusterIds  the clusters the package allows; empty for any
     * @param  Collection<int, PlacementGroup>|null  $groups  placement groups (nodes and storages loaded) to stay in; null for none (docs/adr/0020)
     * @param  Node|null  $node  a node chosen by an administrator; null to pick one
     * @param  string|null  $storage  the name of a storage of the chosen node; null to pick one
     */
    public function __construct(
        public array $clusterIds = [],
        public ?Collection $groups = null,
        public ?Node $node = null,
        public ?string $storage = null,
    ) {}
}
