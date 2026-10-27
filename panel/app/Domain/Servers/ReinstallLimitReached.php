<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Provisioning\Exceptions\ProvisioningException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The server's billing system has used up its reinstalls (docs/adr/0045).
 */
class ReinstallLimitReached extends ProvisioningException
{
    public function __construct(public readonly int $limit, public readonly int $periodDays, public readonly ?CarbonInterface $availableAt)
    {
        if ($limit === 0) {
            parent::__construct('This server cannot be reinstalled through the billing system.');

            return;
        }

        parent::__construct('The reinstall limit of this server (:limit in :days days) has been reached. It can be reinstalled again after :time.', [
            'limit' => (string) $limit,
            'days' => (string) $periodDays,
            'time' => CarbonImmutable::instance($availableAt ?? now())->utc()->format('Y-m-d H:i').' UTC',
        ]);
    }
}
