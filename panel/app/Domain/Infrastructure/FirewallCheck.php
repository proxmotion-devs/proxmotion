<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\DriftType;

/**
 * What FirewallChecker found in one sync: the servers it looked at and the
 * ones whose VM firewall no longer protects against spoofing.
 */
final readonly class FirewallCheck
{
    /**
     * @param  list<int>  $checkedServerIds
     * @param  list<array{DriftType, int, int, array<string, mixed>, array<string, mixed>}>  $findings
     */
    public function __construct(
        public array $checkedServerIds = [],
        public array $findings = [],
    ) {}
}
