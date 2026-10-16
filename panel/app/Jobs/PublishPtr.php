<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Networking\ReverseDns\PtrName;
use App\Domain\Networking\ReverseDns\ReverseDnsDrivers;
use App\Domain\Networking\ReverseDns\ReverseDnsFailed;
use App\Models\IpAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publish an address's current PTR (or remove it when cleared) through its
 * pool's DNS provider. It reads the address when it runs, so a quick second
 * change simply wins. "Record only" pools leave the PTR pending for export.
 */
class PublishPtr implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $addressId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ReverseDnsDrivers $drivers): void
    {
        $address = IpAddress::query()->with('pool.dnsProvider')->find($this->addressId);

        if ($address === null) {
            return;
        }

        $pool = $address->pool;
        $driver = $drivers->for($pool->dnsProvider);

        if (! $driver->publishes()) {
            return;
        }

        if ($pool->reverse_zone === null) {
            throw new ReverseDnsFailed("IP pool {$pool->name} has a DNS provider but no reverse zone.");
        }

        $name = PtrName::for($address->address);
        $ptr = $address->ptr;

        if ($ptr === null) {
            $driver->remove($pool->reverse_zone, $name);
        } else {
            $driver->set($pool->reverse_zone, $name, $ptr);
        }

        // Only if nobody changed it meanwhile; otherwise the newer job publishes.
        IpAddress::query()
            ->whereKey($address->id)
            ->where(fn ($query) => $ptr === null ? $query->whereNull('ptr') : $query->where('ptr', $ptr))
            ->update(['ptr_synced_at' => now()]);
    }
}
