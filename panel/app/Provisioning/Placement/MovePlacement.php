<?php

declare(strict_types=1);

namespace App\Provisioning\Placement;

use App\Models\Node;
use App\Models\Storage;

/**
 * Where a moving server goes: the node and the storage of its disk
 * (docs/adr/0037 §2).
 */
final readonly class MovePlacement
{
    public function __construct(
        public Node $node,
        public Storage $storage,
    ) {}
}
