<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Networking\ProtectedAddresses;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Support\Network\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Chooses IP pools for a new server and assigns free addresses to it.
 * assign() must run inside the provisioning transaction and lock.
 */
final class IpAllocator
{
    /**
     * Highest offset tried when picking the next IPv6 address of a pool.
     */
    private const MAX_IPV6_OFFSET = 65535;

    public function __construct(private readonly ProtectedAddresses $protected) {}

    /**
     * The IPv4 pool with enough free addresses (or null when no IPv4 is needed).
     *
     * @throws ProvisioningException
     */
    public function ipv4Pool(Location $location, NetworkModel $model, int $count): ?IpPool
    {
        if ($count === 0) {
            return null;
        }

        $pool = $this->pools($location, $model, IpVersion::V4)
            ->first(fn (IpPool $pool) => $this->freeIpv4($pool)->count() >= $count);

        return $pool ?? throw ProvisioningException::noAddresses('IPv4');
    }

    /**
     * @throws ProvisioningException
     */
    public function ipv6Pool(Location $location, NetworkModel $model, int $count): ?IpPool
    {
        if ($count === 0) {
            return null;
        }

        return $this->pools($location, $model, IpVersion::V6)->first()
            ?? throw ProvisioningException::noAddresses('IPv6');
    }

    /**
     * Assign addresses to the server. Caller holds the allocation lock.
     *
     * @return list<IpAddress>
     *
     * @throws ProvisioningException
     */
    public function assign(Server $server, ?IpPool $ipv4Pool, int $ipv4Count, ?IpPool $ipv6Pool, int $ipv6Count): array
    {
        $assigned = [];

        if ($ipv4Pool !== null && $ipv4Count > 0) {
            $free = $this->freeIpv4($ipv4Pool)->orderBy('id')->limit($ipv4Count)->lockForUpdate()->get();

            if ($free->count() < $ipv4Count) {
                throw ProvisioningException::noAddresses('IPv4');
            }

            foreach ($free as $address) {
                $assigned[] = $this->give($address, $server);
            }
        }

        if ($ipv6Pool !== null) {
            for ($i = 0; $i < $ipv6Count; $i++) {
                $assigned[] = $this->give($this->nextIpv6($ipv6Pool), $server);
            }
        }

        return $assigned;
    }

    public function release(Server $server): void
    {
        $server->ipAddresses()->update(['server_id' => null, 'assigned_at' => null]);
    }

    /**
     * @return Collection<int, IpPool>
     */
    private function pools(Location $location, NetworkModel $model, IpVersion $version): Collection
    {
        return $location->ipPools()
            ->where('is_active', true)
            ->where('network_model', $model)
            ->where('version', $version)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Builder<IpAddress>
     */
    private function freeIpv4(IpPool $pool): Builder
    {
        return IpAddress::query()
            ->where('ip_pool_id', $pool->id)
            ->whereNull('server_id')
            ->where('is_reserved', false)
            // Defence in depth: never hand out the gateway or a node's own IP,
            // even if it ended up unreserved in the pool.
            ->whereNotIn('address', array_keys($this->protected->for($pool)));
    }

    /**
     * A free (or newly created) IPv6 address from the pool, skipping the gateway.
     *
     * @throws ProvisioningException
     */
    private function nextIpv6(IpPool $pool): IpAddress
    {
        $protected = $this->protected->for($pool);

        $existing = IpAddress::query()->where('ip_pool_id', $pool->id)->whereNull('server_id')->where('is_reserved', false)
            ->whereNotIn('address', array_keys($protected))->orderBy('id')->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        $cidr = Cidr::parse($pool->network);
        $taken = IpAddress::query()->where('ip_pool_id', $pool->id)->pluck('address')->flip();

        for ($offset = 2; $offset <= self::MAX_IPV6_OFFSET; $offset++) {
            $candidate = $cidr->address($offset);

            if (! isset($protected[$candidate]) && ! $taken->has($candidate)) {
                return $pool->addresses()->create(['address' => $candidate]);
            }
        }

        throw ProvisioningException::noAddresses('IPv6');
    }

    private function give(IpAddress $address, Server $server): IpAddress
    {
        $address->forceFill(['server_id' => $server->id, 'assigned_at' => now()])->save();

        return $address;
    }
}
