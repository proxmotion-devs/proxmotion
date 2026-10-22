<?php

namespace Proxmotion\Whmcs;

/**
 * Where UsageUpdate writes the services' traffic (WhmcsUsageStore; faked in the tests).
 */
interface UsageStore
{
    /**
     * Store a service's traffic of the month and its quota in MB (0 = no
     * limit) for WHMCS's usage statistics. Only a service on this WHMCS
     * server is changed; false when there is none.
     */
    public function updateBandwidth(int $serverId, int $serviceId, int $usageMb, int $limitMb): bool;
}
