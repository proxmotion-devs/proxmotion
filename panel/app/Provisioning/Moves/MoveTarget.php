<?php

declare(strict_types=1);

namespace App\Provisioning\Moves;

use App\Models\IpPool;
use App\Models\Node;
use App\Models\PlacementGroup;

/**
 * Where an administrator moves a server (docs/adr/0037 §2): a node, or the
 * least used node of a placement group.
 */
final readonly class MoveTarget
{
    /**
     * @param  Node|null  $node  the node; with a placement group, one of its nodes
     * @param  PlacementGroup|null  $group  a placement group to pick the node from
     * @param  string|null  $storage  a storage of the chosen node by name; null to pick one
     * @param  bool  $routedConfirmed  the administrator confirmed that the provider routes the server's routed addresses to the new node
     * @param  IpPool|null  $ipv4Pool  for a move to another location: the IPv4 pool the new addresses come from; null to pick one
     */
    public function __construct(
        public ?Node $node = null,
        public ?PlacementGroup $group = null,
        public ?string $storage = null,
        public bool $routedConfirmed = false,
        public ?IpPool $ipv4Pool = null,
    ) {}
}
