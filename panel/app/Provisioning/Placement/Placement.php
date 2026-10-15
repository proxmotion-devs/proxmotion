<?php

declare(strict_types=1);

namespace App\Provisioning\Placement;

use App\Models\Node;
use App\Models\ProxmoxTemplate;
use App\Models\Storage;

/**
 * Where a new server goes: the node, the storage for its disk and the VM
 * template it is cloned from.
 */
final readonly class Placement
{
    public function __construct(
        public Node $node,
        public Storage $storage,
        public ProxmoxTemplate $template,
    ) {}
}
