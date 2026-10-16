<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Networking\ReverseDns\PtrName;
use App\Domain\Networking\ReverseDns\ReverseDnsDrivers;
use App\Models\IpPool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remove the PTR of a released address, so the next customer does not
 * inherit the previous customer's name.
 */
class RemovePtr implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public int $poolId,
        public string $address,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ReverseDnsDrivers $drivers): void
    {
        $pool = IpPool::query()->with('dnsProvider')->find($this->poolId);
        $driver = $drivers->for($pool?->dnsProvider);

        if ($pool === null || $pool->reverse_zone === null || ! $driver->publishes()) {
            return;
        }

        $driver->remove($pool->reverse_zone, PtrName::for($this->address));
    }
}
