<?php

namespace Proxmotion\Whmcs;

use WHMCS\Database\Capsule;

/**
 * The usage statistics of WHMCS services (tblhosting), as WHMCS's own
 * server modules write them from UsageUpdate.
 */
class WhmcsUsageStore implements UsageStore
{
    public function updateBandwidth(int $serverId, int $serviceId, int $usageMb, int $limitMb): bool
    {
        return Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->where('server', $serverId)
            ->update([
                'bwusage' => $usageMb,
                'bwlimit' => $limitMb,
                'lastupdate' => date('Y-m-d H:i:s'),
            ]) > 0;
    }
}
