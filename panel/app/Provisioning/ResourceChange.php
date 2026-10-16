<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Package;

/**
 * New resources for an existing server: from a package (or custom when
 * $package is null), as absolute values. Address counts are the totals the
 * server should have.
 */
final readonly class ResourceChange
{
    public function __construct(
        public ServerSpec $spec,
        public ?Package $package = null,
        /** Restart the VM right away if CPU or memory changes wait for a restart. */
        public bool $reboot = false,
    ) {}
}
