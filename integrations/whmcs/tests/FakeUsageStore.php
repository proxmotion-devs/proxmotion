<?php

namespace Proxmotion\Whmcs\Tests;

use Proxmotion\Whmcs\UsageStore;

/**
 * WHMCS's usage statistics, in memory.
 */
class FakeUsageStore implements UsageStore
{
    /** @var array<int, int> service id => WHMCS server id of the services that exist */
    public $services = [];

    /** @var array<int, array{bwusage: int, bwlimit: int}> service id => what was written */
    public $written = [];

    public function updateBandwidth(int $serverId, int $serviceId, int $usageMb, int $limitMb): bool
    {
        if (!isset($this->services[$serviceId]) || $this->services[$serviceId] !== $serverId) {
            return false;
        }

        $this->written[$serviceId] = ['bwusage' => $usageMb, 'bwlimit' => $limitMb];

        return true;
    }
}
