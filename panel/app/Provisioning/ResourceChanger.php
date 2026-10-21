<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\IpVersion;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Placement\NodePlacer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Changes an active server's resources (upgrade/downgrade). Like creating,
 * the new capacity and addresses are reserved under the location's lock;
 * the Proxmox work runs as a task (ChangeResourcesPipeline).
 *
 * Rules: the disk only grows (a smaller disk keeps its size), CPU and memory
 * of a running VM take effect at its next restart unless $reboot, the server
 * stays on its node (no migration), and the primary address of each IP
 * version is never released; surplus addresses are the newest ones.
 */
final readonly class ResourceChanger
{
    public function __construct(
        private NodePlacer $placer,
        private IpAllocator $ips,
        private TaskRunner $tasks,
    ) {}

    /**
     * @throws ProvisioningException when the node lacks capacity, no addresses are free or another task runs
     */
    public function request(Server $server, ResourceChange $change, User|ApiToken $actor, ?string $ipAddress): Task
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $before = $this->resourcesOf($server);

        $task = Cache::lock("provisioning:location:{$server->location_id}", 30)->block(15, function () use ($server, $change, $actor) {
            return DB::transaction(function () use ($server, $change, $actor) {
                $server->refresh();
                $target = $this->target($server, $change->spec);

                if (! $this->placer->fitsChange($server, $target)) {
                    throw new ProvisioningException("The server's node does not have enough free capacity for these resources.");
                }

                $release = $this->reserveAddresses($server, $target);
                $growsDisk = $target->diskMib > $server->disk_mib;

                $server->forceFill([
                    'package_id' => $change->package?->id,
                    'cpu_cores' => $target->cpuCores,
                    'memory_mib' => $target->memoryMib,
                    'disk_mib' => $target->diskMib,
                    'port_speed_mbps' => $target->portSpeedMbps,
                    'traffic_bytes' => $target->trafficBytes,
                    ...$target->limits->columns(),
                ])->save();

                return $this->tasks->start($server, TaskType::ChangeResources, $actor, input: [
                    'release_address_ids' => $release,
                    'reboot' => $change->reboot,
                    'grow_disk' => $growsDisk,
                ]);
            });
        });

        AuditLog::record('server.resources_changed', $actor, $server, [
            'hostname' => $server->hostname,
            'before' => $before,
            'after' => $this->resourcesOf($server->refresh()),
        ], $ipAddress);

        return $task;
    }

    /**
     * The requested resources, with the disk never smaller than now.
     */
    private function target(Server $server, ServerSpec $spec): ServerSpec
    {
        return new ServerSpec(
            cpuCores: $spec->cpuCores,
            memoryMib: $spec->memoryMib,
            diskMib: max($server->disk_mib, $spec->diskMib),
            ipv4Count: $spec->ipv4Count,
            ipv6Count: $spec->ipv6Count,
            portSpeedMbps: $spec->portSpeedMbps,
            trafficBytes: $spec->trafficBytes,
            limits: $spec->limits,
        );
    }

    /**
     * Assign additional addresses now; return the ids of surplus ones, which
     * the task releases once they are out of the VM's firewall.
     *
     * @return list<int>
     *
     * @throws ProvisioningException
     */
    private function reserveAddresses(Server $server, ServerSpec $target): array
    {
        $release = [];

        foreach ([IpVersion::V4->value => $target->ipv4Count, IpVersion::V6->value => $target->ipv6Count] as $number => $wanted) {
            $version = IpVersion::from($number);
            $current = $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', $version))->count();

            if ($wanted > $current) {
                $missing = $wanted - $current;
                $location = $server->location;

                $version === IpVersion::V4
                    ? $this->ips->assign($server, $this->ips->ipv4Pool($location, $server->network_model, $missing), $missing, null, 0)
                    : $this->ips->assign($server, null, 0, $this->ips->ipv6Pool($location, $server->network_model, $missing), $missing);
            } elseif ($wanted < $current) {
                foreach ($this->ips->surplus($server, $version, $wanted) as $address) {
                    $release[] = $address->id;
                }
            }
        }

        return $release;
    }

    /**
     * @return array<string, int|float|null>
     */
    private function resourcesOf(Server $server): array
    {
        return [
            'cpu_cores' => $server->cpu_cores,
            'memory_mib' => $server->memory_mib,
            'disk_mib' => $server->disk_mib,
            'ipv4_count' => $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', IpVersion::V4))->count(),
            'ipv6_count' => $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('version', IpVersion::V6))->count(),
            'port_speed_mbps' => $server->port_speed_mbps,
            'traffic_bytes' => $server->traffic_bytes,
            ...ResourceLimits::of($server)->columns(),
        ];
    }
}
