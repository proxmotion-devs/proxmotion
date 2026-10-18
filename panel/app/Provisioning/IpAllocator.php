<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Networking\ProtectedAddresses;
use App\Domain\Networking\ReverseDns\ReverseDns;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Support\Network\Cidr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    public function __construct(
        private readonly ProtectedAddresses $protected,
        private readonly ReverseDns $reverseDns,
    ) {}

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
     * The IPv6 pool, or null when no IPv6 is needed or the location has no
     * active IPv6 pool for the network model: servers there get no IPv6
     * addresses. A pool that has run out still fails in assign().
     */
    public function ipv6Pool(Location $location, NetworkModel $model, int $count): ?IpPool
    {
        if ($count === 0) {
            return null;
        }

        return $this->pools($location, $model, IpVersion::V6)->first();
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

    /**
     * One free address of the given version from the active pools of the
     * location and network model, locked for update: $address when given
     * (a free, unreserved address of one of those pools; an IPv6 address
     * inside a pool's network may be new), else the next free one. Caller
     * holds the allocation lock and assigns it. With $sameNetworkAs, only pools
     * on the same bridge and VLAN qualify (the VM's net0 stays as it is).
     *
     * @throws ProvisioningException
     */
    public function freeAddress(Location $location, NetworkModel $model, IpVersion $version, ?string $address = null, ?IpPool $sameNetworkAs = null): IpAddress
    {
        $pools = $this->pools($location, $model, $version)->filter(fn (IpPool $pool) => $sameNetworkAs === null
            || ($pool->bridge === $sameNetworkAs->bridge && $pool->vlan_tag === $sameNetworkAs->vlan_tag));
        $label = $version === IpVersion::V4 ? 'IPv4' : 'IPv6';

        if ($address === null) {
            foreach ($pools as $pool) {
                $free = $version === IpVersion::V4
                    ? $this->freeIpv4($pool)->orderBy('id')->lockForUpdate()->first()
                    : $this->nextIpv6OrNull($pool);

                if ($free !== null) {
                    return $free;
                }
            }

            throw ProvisioningException::noAddresses($label);
        }

        foreach ($pools as $pool) {
            if (! Cidr::parse($pool->network)->contains($address) || isset($this->protected->for($pool)[$address])) {
                continue;
            }

            $existing = IpAddress::query()->where('ip_pool_id', $pool->id)->where('address', $address)->lockForUpdate()->first();

            if ($existing === null && $version === IpVersion::V6) {
                return $pool->addresses()->create(['address' => $address]);
            }

            if ($existing !== null && $existing->server_id === null && ! $existing->is_reserved) {
                return $existing;
            }
        }

        throw new ProvisioningException(':address is not a free address of the pools this server can use.', ['address' => $address]);
    }

    public function freeIpv4Count(IpPool $pool): int
    {
        return $this->freeIpv4($pool)->count();
    }

    /**
     * Whether a server of this location and network model may take IPv4
     * addresses from the pool (an active IPv4 pool of the same location and model).
     */
    public function usableIpv4Pool(IpPool $pool, Location $location, NetworkModel $model): bool
    {
        return $this->pools($location, $model, IpVersion::V4)->contains('id', $pool->id);
    }

    /**
     * A free, unreserved IPv4 address of this pool, locked for update. Caller
     * holds the allocation lock and assigns it.
     *
     * @throws ProvisioningException
     */
    public function freeIpv4Of(IpPool $pool, string $address): IpAddress
    {
        return $this->freeIpv4($pool)->where('address', $address)->lockForUpdate()->first()
            ?? throw new ProvisioningException(':address is not a free address of the pool :pool.', ['address' => $address, 'pool' => $pool->name]);
    }

    /**
     * The free IPv4 addresses of a pool in allocation order, for choosing them by hand.
     *
     * @return list<string>
     */
    public function freeIpv4Addresses(IpPool $pool, int $limit): array
    {
        return array_values($this->freeIpv4($pool)->orderBy('id')->limit($limit)->pluck('address')->map(fn ($address) => (string) $address)->all());
    }

    public function release(Server $server): void
    {
        $this->reverseDns->release($server);
        $server->ipAddresses()->update(['server_id' => null, 'is_primary' => false, 'assigned_at' => null]);
    }

    /**
     * Addresses to give up when the server keeps only $keep of this version:
     * the newest ones. The primary address (the first of its version, the one
     * cloud-init configures) always stays.
     *
     * @return EloquentCollection<int, IpAddress>
     */
    public function surplus(Server $server, IpVersion $version, int $keep): EloquentCollection
    {
        $addresses = $server->ipAddresses()
            ->whereHas('pool', fn ($query) => $query->where('version', $version))
            ->orderBy('id')
            ->get();

        return $addresses->slice(max(1, $keep))->values();
    }

    /**
     * Release some of a server's addresses (and their PTRs).
     *
     * @param  EloquentCollection<int, IpAddress>  $addresses
     */
    public function releaseAddresses(EloquentCollection $addresses): void
    {
        foreach ($addresses as $address) {
            $this->reverseDns->releaseAddress($address);
            $address->forceFill(['server_id' => null, 'is_primary' => false, 'assigned_at' => null])->save();
        }
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

    private function nextIpv6OrNull(IpPool $pool): ?IpAddress
    {
        try {
            return $this->nextIpv6($pool);
        } catch (ProvisioningException) {
            return null;
        }
    }

    private function give(IpAddress $address, Server $server): IpAddress
    {
        $address->forceFill(['server_id' => $server->id, 'assigned_at' => now()])->save();

        return $address;
    }
}
