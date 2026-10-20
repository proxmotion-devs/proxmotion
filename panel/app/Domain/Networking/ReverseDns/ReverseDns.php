<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

use App\Jobs\PublishPtr;
use App\Jobs\RemovePtr;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\User;

/**
 * Reverse DNS of server addresses (docs/adr/0003). The panel stores the
 * requested name; a queued job publishes it through the pool's driver.
 */
final class ReverseDns
{
    /**
     * Set (or with null, remove) the PTR of an address.
     */
    public function update(IpAddress $address, ?string $hostname, User|ApiToken $actor, ?string $ipAddress): void
    {
        $hostname = $hostname !== null && trim($hostname) !== '' ? strtolower(rtrim(trim($hostname), '.')) : null;
        $previous = $address->ptr;

        $address->forceFill(['ptr' => $hostname, 'ptr_synced_at' => null])->save();
        PublishPtr::dispatch($address->id)->afterCommit();

        AuditLog::record('rdns.updated', $actor, $address->server, [
            'address' => $address->address,
            'from' => $previous,
            'to' => $hostname,
        ], $ipAddress);
    }

    /**
     * The PTR of a hostname generated for a new server (docs/adr/0031). The
     * location's template is the administrators' own, so the pool's forward
     * check for requested names does not apply.
     */
    public function setGenerated(IpAddress $address, string $hostname): void
    {
        $address->forceFill(['ptr' => $hostname, 'ptr_synced_at' => null])->save();
        PublishPtr::dispatch($address->id)->afterCommit();
    }

    /**
     * Before a server's addresses are released: drop their PTRs, so the next
     * customer does not inherit a name.
     */
    public function release(Server $server): void
    {
        foreach ($server->ipAddresses()->whereNotNull('ptr')->get() as $address) {
            $this->releaseAddress($address);
        }
    }

    /**
     * Remove the PTR of an address that leaves its server.
     */
    public function releaseAddress(IpAddress $address): void
    {
        if ($address->ptr === null) {
            return;
        }

        RemovePtr::dispatch($address->ip_pool_id, $address->address)->afterCommit();
        $address->forceFill(['ptr' => null, 'ptr_synced_at' => null])->save();
    }
}
