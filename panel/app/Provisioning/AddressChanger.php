<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Edits a server's addresses (docs/adr/0028): adds chosen or next free
 * addresses, removes chosen ones, and changes the primary of each IP version,
 * or applies the addresses again without changing them.
 *
 * The addresses are decided under the location's lock; the Proxmox and guest
 * work runs as a change_addresses task (AddressesPipeline), which releases the
 * removed addresses once they are out of the firewall.
 */
final readonly class AddressChanger
{
    public function __construct(
        private IpAllocator $ips,
        private TaskRunner $tasks,
    ) {}

    /**
     * @throws ProvisioningException
     */
    public function request(Server $server, AddressChange $change, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);

        if ($change->isEmpty()) {
            throw new ProvisioningException('Nothing to change.');
        }

        $before = self::addressesOf($server);

        $result = Cache::lock("provisioning:location:{$server->location_id}", 30)->block(15, function () use ($server, $change, $actor) {
            return DB::transaction(function () use ($server, $change, $actor) {
                $server->refresh();
                /** @var EloquentCollection<int, IpAddress> $current */
                $current = $server->ipAddresses()->with('pool')->get();

                $remove = $this->removals($current, $change);
                $added = $this->additions($server, $current, $change);
                $primaries = $this->choosePrimaries($server, $current, $remove, $added, $change);

                $task = $this->tasks->start($server, TaskType::ChangeAddresses, $actor, input: ['release_address_ids' => array_keys($remove)]);

                return [
                    'task' => $task,
                    'added' => array_map(fn (IpAddress $address) => $address->address, $added),
                    'removed' => array_values(array_map(fn (IpAddress $address) => $address->address, $remove)),
                    'primary' => $primaries,
                ];
            });
        });

        $after = array_values(array_diff(self::addressesOf($server), $result['removed']));

        AuditLog::record('server.addresses_changed', $actor, $server, [
            'hostname' => $server->hostname,
            'before' => $before,
            'after' => $after,
            'added' => $result['added'],
            'removed' => $result['removed'],
            'primary' => $result['primary'],
        ], $ipAddress);

        return $result['task'];
    }

    /**
     * Set the firewall IP set to the server's addresses and run the address
     * guest script again, changing nothing in the panel.
     *
     * @throws ProvisioningException
     */
    public function reapply(Server $server, User|ApiToken $actor, ?string $ipAddress): Task
    {
        self::ensureActive($server);

        $task = $this->tasks->start($server, TaskType::ChangeAddresses, $actor, input: ['release_address_ids' => [], 'reapply' => true]);

        AuditLog::record('server.addresses_reapplied', $actor, $server, [
            'hostname' => $server->hostname,
            'addresses' => self::addressesOf($server),
        ], $ipAddress);

        return $task;
    }

    /**
     * @param  EloquentCollection<int, IpAddress>  $current
     * @return array<int, IpAddress> by id
     *
     * @throws ProvisioningException
     */
    private function removals(EloquentCollection $current, AddressChange $change): array
    {
        $remove = [];

        foreach ($change->remove as $address) {
            $address = PrimaryAddressChanger::normalize($address);
            $own = $current->first(fn (IpAddress $candidate) => $candidate->address === $address)
                ?? throw new ProvisioningException(':address is not an address of this server.', ['address' => $address]);
            $remove[$own->id] = $own;
        }

        return $remove;
    }

    /**
     * Assign the added addresses now, from pools on the server's bridge and VLAN.
     *
     * @param  EloquentCollection<int, IpAddress>  $current
     * @return list<IpAddress>
     *
     * @throws ProvisioningException
     */
    private function additions(Server $server, EloquentCollection $current, AddressChange $change): array
    {
        // Every address of the server is on net0, so all share one bridge and VLAN.
        $network = $current->first()?->pool;
        $mac = $current->first()?->mac_address;
        $added = [];

        foreach ($change->add as $addition) {
            if ($addition->pool !== null && ! $this->usable($server, $addition->version, $network, $addition->pool)) {
                throw new ProvisioningException('The pool :pool cannot be used for this server.', ['pool' => $addition->pool->name]);
            }

            $address = $addition->address === null ? null : PrimaryAddressChanger::normalize($addition->address);
            $new = $this->ips->freeAddress($server->location, $server->network_model, $addition->version, $address, sameNetworkAs: $network, pool: $addition->pool);

            // A provider-issued virtual MAC (failover addresses) lives in net0, which needs a restart.
            if ($new->mac_address !== null && strcasecmp($new->mac_address, (string) $mac) !== 0) {
                throw new ProvisioningException(':address needs its own MAC address; reinstall the server with it instead.', ['address' => $new->address]);
            }

            $new->forceFill(['server_id' => $server->id, 'is_primary' => false, 'assigned_at' => now()])->save();
            $added[] = $new->loadMissing('pool');
        }

        return $added;
    }

    private function usable(Server $server, IpVersion $version, ?IpPool $network, IpPool $pool): bool
    {
        return $this->ips->sameNetworkPools($server->location, $server->network_model, $version, $network)->contains('id', $pool->id);
    }

    /**
     * Keep one primary per IP version: the chosen one, else the current one
     * (refused when it is removed), else the first added one. The chosen
     * primary is always flagged, so the oldest address never takes its place.
     *
     * @param  EloquentCollection<int, IpAddress>  $current
     * @param  array<int, IpAddress>  $remove
     * @param  list<IpAddress>  $added
     * @return array<string, array{before: string|null, after: string}> the primaries that changed, by version
     *
     * @throws ProvisioningException
     */
    private function choosePrimaries(Server $server, EloquentCollection $current, array $remove, array $added, AddressChange $change): array
    {
        $changed = [];

        foreach (IpVersion::cases() as $version) {
            $label = $version === IpVersion::V4 ? 'IPv4' : 'IPv6';
            $ofVersion = $current->filter(fn (IpAddress $address) => $address->pool->version === $version);
            $currentPrimary = $ofVersion->first();
            $kept = [
                ...$ofVersion->reject(fn (IpAddress $address) => isset($remove[$address->id]))->values()->all(),
                ...array_filter($added, fn (IpAddress $address) => $address->pool->version === $version),
            ];

            if ($ofVersion->isNotEmpty() && $kept === []) {
                throw new ProvisioningException('The server keeps at least one :version address. Change its resources to remove them all.', ['version' => $label]);
            }

            $wanted = $change->primaryOf($version);

            if ($wanted !== null) {
                $primary = collect($kept)->first(fn (IpAddress $address) => $address->address === $wanted)
                    ?? throw new ProvisioningException(':address is not an address the server keeps.', ['address' => $wanted]);
            } elseif ($currentPrimary !== null && isset($remove[$currentPrimary->id])) {
                throw new ProvisioningException('Choose a new primary address before removing :address.', ['address' => $currentPrimary->address]);
            } else {
                $primary = $currentPrimary ?? ($kept[0] ?? null);
            }

            if ($primary === null) {
                continue;
            }

            if (! $primary->is_primary) {
                $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', $version))->update(['is_primary' => false]);
                $primary->forceFill(['is_primary' => true])->save();
            }

            if ($currentPrimary === null || ! $primary->is($currentPrimary)) {
                $changed[$label] = ['before' => $currentPrimary?->address, 'after' => $primary->address];
            }
        }

        return $changed;
    }

    /**
     * @throws ProvisioningException
     */
    private static function ensureActive(Server $server): void
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }
    }

    /**
     * @return list<string>
     */
    private static function addressesOf(Server $server): array
    {
        return array_values($server->ipAddresses()->pluck('address')->map(fn ($address) => (string) $address)->all());
    }
}
