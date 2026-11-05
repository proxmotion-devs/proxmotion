<?php

declare(strict_types=1);

namespace App\Domain\Installation;

use App\Models\BillingSystem;
use App\Models\Cluster;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;

/**
 * Which steps of the setup order an installation has done, in that order:
 * shown on Help, and on the dashboard until every step is done, so a new
 * panel says what to do next instead of reporting a healthy fleet it has
 * not checked yet.
 */
class SetupProgress
{
    /**
     * @return array{locations: bool, clusters: bool, ip_pools: bool, os_templates: bool, packages: bool, billing_systems: bool}
     */
    public function steps(): array
    {
        return [
            'locations' => Location::query()->exists(),
            'clusters' => Cluster::query()->exists(),
            'ip_pools' => IpPool::query()->exists(),
            'os_templates' => OsTemplate::query()->exists(),
            'packages' => Package::query()->exists(),
            'billing_systems' => BillingSystem::query()->exists(),
        ];
    }
}
