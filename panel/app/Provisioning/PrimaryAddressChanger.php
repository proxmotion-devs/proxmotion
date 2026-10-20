<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Changes the primary address of one IP version (docs/adr/0011). The number
 * of addresses never changes:
 *
 * - one of the server's additional addresses becomes the primary, the old
 *   primary becomes an additional address (swap);
 * - a free address from a pool (the given one or the next free one) becomes
 *   the primary, the old primary is released by the task once it is out of
 *   the firewall.
 *
 * The addresses are decided under the location's lock; the Proxmox and guest
 * work runs as a change_primary_address task (PrimaryAddressPipeline).
 */
final readonly class PrimaryAddressChanger
{
    public function __construct(
        private IpAllocator $ips,
        private TaskRunner $tasks,
    ) {}

    /**
     * @param  string|null  $address  an additional address of the server, a free address of a pool, or null for the next free one
     *
     * @throws ProvisioningException
     */
    public function request(Server $server, IpVersion $version, ?string $address, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $address = $address === null ? null : self::normalize($address);
        $result = Cache::lock("provisioning:location:{$server->location_id}", 30)->block(15, function () use ($server, $version, $address, $actor) {
            return DB::transaction(function () use ($server, $version, $address, $actor) {
                $current = $this->primaryOf($server, $version)
                    ?? throw new ProvisioningException('The server has no :version address.', ['version' => $version === IpVersion::V4 ? 'IPv4' : 'IPv6']);

                if ($address === $current->address) {
                    throw new ProvisioningException(':address is already the primary address.', ['address' => $address]);
                }

                $own = $address === null ? null : $server->ipAddresses()
                    ->whereHas('pool', fn ($query) => $query->where('version', $version))
                    ->where('address', $address)
                    ->first();

                if ($own !== null) {
                    $release = [];
                    $new = $own;
                } else {
                    $new = $this->ips->freeAddress($server->location, $server->network_model, $version, $address, sameNetworkAs: $current->pool);
                    $release = [$current->id];
                }

                // A provider-issued virtual MAC (failover addresses) lives in net0, which needs a restart.
                if ($new->mac_address !== null && strcasecmp($new->mac_address, (string) $current->mac_address) !== 0) {
                    throw new ProvisioningException(':address needs its own MAC address; reinstall the server with it instead.', ['address' => $new->address]);
                }

                $server->ipAddresses()->whereKey($current->id)->update(['is_primary' => false]);
                $new->forceFill(['server_id' => $server->id, 'is_primary' => true, 'assigned_at' => $new->assigned_at ?? now()])->save();

                $task = $this->tasks->start($server, TaskType::ChangePrimaryAddress, $actor, input: ['release_address_ids' => $release]);

                return ['task' => $task, 'before' => $current->address, 'after' => $new->address, 'released' => $release !== []];
            });
        });

        AuditLog::record('server.primary_address_changed', $actor, $server, [
            'hostname' => $server->hostname,
            'version' => $version->value,
            'before' => $result['before'],
            'after' => $result['after'],
            'released' => $result['released'],
        ], $ipAddress);

        return $result['task'];
    }

    private function primaryOf(Server $server, IpVersion $version): ?IpAddress
    {
        return $server->ipAddresses()->with('pool')->whereHas('pool', fn ($query) => $query->where('version', $version))->first();
    }

    /**
     * The canonical text form, so "2001:db8:0::5" finds "2001:db8::5".
     */
    public static function normalize(string $address): string
    {
        $packed = @inet_pton($address);

        return $packed === false ? $address : (string) inet_ntop($packed);
    }
}
