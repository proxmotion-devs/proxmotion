<?php

declare(strict_types=1);

namespace App\Provisioning\Moves;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Enums\IpVersion;
use App\Enums\NetworkModel;
use App\Enums\ServerMoveKind;
use App\Enums\ServerMoveStatus;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\PlacementGroup;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\IpAllocator;
use App\Provisioning\Placement\MovePlacement;
use App\Provisioning\Placement\NodePlacer;
use App\Provisioning\Placement\PlacementRules;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use App\Provisioning\VmidAllocator;
use App\Proxmox\Data\BootDisk;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves a server to another node, cluster or location (docs/adr/0037):
 * checks that it can go there, reserves the target VMID and, for another
 * location, the new addresses under the provisioning lock of the locations
 * involved, and queues the move task. Administrators only.
 */
final readonly class ServerMover
{
    public function __construct(
        private NodePlacer $placer,
        private IpAllocator $ips,
        private VmidAllocator $vmids,
        private ProxmoxClientFactory $clients,
        private TaskRunner $tasks,
    ) {}

    /**
     * Whether moves to other clusters are turned on (PROXMOTION_CROSS_CLUSTER_MOVES).
     */
    public static function crossClusterEnabled(): bool
    {
        return (bool) config('proxmotion.moves.cross_cluster');
    }

    /**
     * Why the server cannot move into this cluster, or null. Its own cluster always takes it.
     */
    public static function clusterRefusal(Server $server, Cluster $cluster): ?string
    {
        if ($cluster->id === $server->cluster_id) {
            return null;
        }

        if (! self::crossClusterEnabled()) {
            return 'Moving servers to another cluster is turned off (PROXMOTION_CROSS_CLUSTER_MOVES).';
        }

        if (! $cluster->receivesMoves()) {
            return 'The target cluster has no incoming move token, so servers cannot be moved into it.';
        }

        return null;
    }

    /**
     * Why the server cannot be moved right now, or null.
     */
    public static function serverRefusal(Server $server): ?string
    {
        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true)) {
            return 'A server can only be moved while it is active or suspended.';
        }

        if ($server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists()) {
            return 'Another task is still running for this server.';
        }

        $scripts = $server->appInstallations()->whereIn('status', [AppInstallationStatus::Queued, AppInstallationStatus::Installing, AppInstallationStatus::Uninstalling, AppInstallationStatus::Upgrading])->exists()
            || AppRun::query()->whereIn('status', [AppRunStatus::Queued, AppRunStatus::Running])->whereHas('installation', fn ($query) => $query->where('server_id', $server->id))->exists();

        return $scripts ? 'An app script is running on this server: wait until it has finished.' : null;
    }

    /**
     * Whether some of the server's addresses are routed to its node by the
     * provider (routed pools): the move needs the administrator to confirm
     * that they follow it (docs/adr/0037 §2).
     */
    public static function hasRoutedAddresses(Server $server): bool
    {
        return $server->ipAddresses()->whereHas('pool', fn ($query) => $query->where('network_model', NetworkModel::Routed))->exists();
    }

    /**
     * @throws ProvisioningException
     * @throws ProxmoxException
     */
    public function request(Server $server, MoveTarget $target, User $actor, ?string $ipAddress): ServerMove
    {
        if ($server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists()) {
            throw new TaskAlreadyRunning;
        }

        if (($refusal = self::serverRefusal($server)) !== null) {
            throw new ProvisioningException($refusal);
        }

        if ($server->network_model === NetworkModel::Nat) {
            throw ProvisioningException::unsupportedNetworkModel();
        }

        if (self::hasRoutedAddresses($server) && ! $target->routedConfirmed) {
            throw new ProvisioningException('Confirm that the provider routes the addresses of this server to the new node.');
        }

        $location = $this->targetLocation($target);
        $sameLocation = $location->id === $server->location_id;
        $spec = $this->spec($server);
        [$ipv4Pool, $ipv6Pool] = $sameLocation ? [null, null] : $this->targetPools($server, $location, $spec, $target->ipv4Pool);
        $bridge = $sameLocation ? $this->currentBridge($server) : ($ipv4Pool ?? $ipv6Pool)->bridge ?? 'vmbr0';
        $groups = $target->group === null ? null : new Collection([$target->group->load(['nodes', 'storages'])]);

        $this->checkVm($server);

        // Both locations' locks, always in the same order: the target's addresses and VMIDs are reserved.
        $locks = array_values(array_unique([$server->location_id, $location->id]));
        sort($locks);

        $move = $this->locked($locks, function () use ($server, $target, $spec, $bridge, $groups, $location, $sameLocation, $ipv4Pool, $ipv6Pool, $actor) {
            $placement = $this->placer->placeMove(
                $server,
                $spec,
                $bridge,
                new PlacementRules(groups: $groups, node: $target->node, storage: $target->storage),
                fn (Cluster $cluster) => self::clusterRefusal($server, $cluster),
            );
            $kind = match (true) {
                $placement->node->cluster_id === $server->cluster_id => ServerMoveKind::Node,
                $sameLocation => ServerMoveKind::Cluster,
                default => ServerMoveKind::Location,
            };

            if ($kind->crossesClusters() && $placement->node->apiHost() === null) {
                throw new ProvisioningException('The address of the target node is not known yet: sync its cluster, or set its API address on the node.');
            }

            if ($kind === ServerMoveKind::Node) {
                $this->checkMigration($server, $placement);
            }

            $vmid = $kind === ServerMoveKind::Node ? $server->vmid : $this->vmids->firstFree($placement->node->cluster, preferred: $server->vmid);

            return DB::transaction(function () use ($server, $placement, $kind, $vmid, $location, $ipv4Pool, $ipv6Pool, $spec, $actor) {
                $move = $this->insertMove($server, $placement, $kind, $vmid, $location, $actor);

                if ($kind === ServerMoveKind::Location) {
                    $this->ips->assign($move, $ipv4Pool, $spec->ipv4Count, $ipv6Pool, $spec->ipv6Count);
                }

                return $move;
            });
        });

        DB::transaction(function () use ($server, $move, $actor, $ipAddress) {
            $task = $this->tasks->start($server, TaskType::Move, $actor);
            $task->forceFill(['server_move_id' => $move->id])->save();

            AuditLog::record('server.move_started', $actor, $server, $this->auditProperties($move), $ipAddress);
        });

        return $move;
    }

    /**
     * Run the steps of a move again whose VM Proxmox already moved, after a
     * later step failed (docs/adr/0037 §7).
     *
     * @throws ProvisioningException
     */
    public function finish(ServerMove $move, User $actor, ?string $ipAddress): void
    {
        $server = $move->server;

        if (! $move->canFinish() || $server->moves()->first()?->isNot($move)) {
            throw new ProvisioningException('This move cannot be finished again.');
        }

        if ($server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists()) {
            throw new TaskAlreadyRunning;
        }

        DB::transaction(function () use ($server, $move, $actor, $ipAddress) {
            $move->forceFill(['status' => ServerMoveStatus::Queued, 'error' => null, 'finished_at' => null])->save();
            $task = $this->tasks->start($server, TaskType::Move, $actor);
            $task->forceFill(['server_move_id' => $move->id])->save();

            AuditLog::record('server.move_resumed', $actor, $server, $this->auditProperties($move), $ipAddress);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function auditProperties(ServerMove $move): array
    {
        $move->loadMissing(['sourceCluster', 'sourceNode', 'targetCluster', 'targetNode', 'reservedAddresses']);

        return [
            'hostname' => $move->server->hostname,
            'kind' => $move->kind->value,
            'from' => ['cluster' => $move->sourceCluster->name, 'node' => $move->sourceNode->name, 'vmid' => $move->source_vmid],
            'to' => ['cluster' => $move->targetCluster->name, 'node' => $move->targetNode->name, 'vmid' => $move->target_vmid],
            'new_addresses' => $move->reservedAddresses->pluck('address')->all(),
        ];
    }

    /**
     * @throws ProvisioningException
     */
    private function targetLocation(MoveTarget $target): Location
    {
        if ($target->node !== null) {
            return $target->node->cluster->location;
        }

        if ($target->group instanceof PlacementGroup) {
            return $target->group->location;
        }

        throw new ProvisioningException('Choose a node or a placement group.');
    }

    private function spec(Server $server): ServerSpec
    {
        $counts = $server->ipAddresses()->with('pool')->get()->countBy(fn (IpAddress $address) => $address->pool->version->value);

        return new ServerSpec(
            cpuCores: $server->cpu_cores,
            memoryMib: $server->memory_mib,
            diskMib: $server->disk_mib,
            ipv4Count: (int) ($counts[IpVersion::V4->value] ?? 0),
            ipv6Count: (int) ($counts[IpVersion::V6->value] ?? 0),
        );
    }

    /**
     * The bridge of the server's primary address, which its net0 is on.
     */
    private function currentBridge(Server $server): string
    {
        return $server->ipAddresses()->with('pool')->first()->pool->bridge ?? 'vmbr0';
    }

    /**
     * The pools of the target location the new addresses come from: as many
     * of each version as the server has, IPv6 on the IPv4 pool's bridge and
     * VLAN (one net0). A server never loses a version of its addresses.
     *
     * @return array{IpPool|null, IpPool|null}
     *
     * @throws ProvisioningException
     */
    private function targetPools(Server $server, Location $location, ServerSpec $spec, ?IpPool $chosen): array
    {
        if ($chosen !== null && ! $this->ips->usableIpv4Pool($chosen, $location, $server->network_model)) {
            throw new ProvisioningException('The IPv4 pool :pool is not an active pool of this location for the chosen network model.', ['pool' => $chosen->name]);
        }

        $ipv4Pool = $spec->ipv4Count === 0 ? null : ($chosen ?? $this->ips->ipv4Pool($location, $server->network_model, $spec->ipv4Count));

        if ($ipv4Pool !== null && $this->ips->freeIpv4Count($ipv4Pool) < $spec->ipv4Count) {
            throw ProvisioningException::noAddresses('IPv4');
        }

        $ipv6Pool = null;

        if ($spec->ipv6Count > 0) {
            $ipv6Pool = $this->ips->sameNetworkPools($location, $server->network_model, IpVersion::V6, $ipv4Pool)->first()
                ?? throw new ProvisioningException('The target location has no IPv6 pool for this server on the same bridge and VLAN.');
        }

        return [$ipv4Pool, $ipv6Pool];
    }

    /**
     * What Proxmox's configuration says against a move: the VM must exist
     * on its node and have no snapshots (docs/adr/0037 §2).
     *
     * @throws ProvisioningException
     * @throws ProxmoxException
     */
    private function checkVm(Server $server): void
    {
        $config = $this->clients->forCluster($server->cluster)->qemu($server->node->name)->config($server->vmid)
            ?? throw new ProvisioningException('The VM of this server is not on its node.');

        if (isset($config['parent'])) {
            throw new ProvisioningException('The VM has snapshots: delete them before moving the server.');
        }
    }

    /**
     * Within a cluster: Proxmox's own preconditions, and no linked clone on
     * local storage, whose base image is only on its node (docs/adr/0037 §2).
     *
     * @throws ProvisioningException
     * @throws ProxmoxException
     */
    private function checkMigration(Server $server, MovePlacement $placement): void
    {
        $qemu = $this->clients->forCluster($server->cluster)->qemu($server->node->name);
        $config = $qemu->config($server->vmid) ?? [];
        $disk = BootDisk::fromConfig($config);
        $volume = $disk === null ? '' : (string) ($config[$disk->key] ?? '');

        if (preg_match('#^[^:]+:base-\d+-disk-\d+/#', $volume) === 1 && ! ($server->storage->is_shared ?? false)) {
            throw new ProvisioningException('The server is a linked clone on local storage and cannot be moved to another node yet.');
        }

        $refusal = $qemu->migrationPreconditions($server->vmid, $placement->node->name)->refusal($placement->node->name);

        if ($refusal !== null) {
            throw new ProvisioningException($refusal);
        }
    }

    private function insertMove(Server $server, MovePlacement $placement, ServerMoveKind $kind, int $vmid, Location $location, User $actor): ServerMove
    {
        $move = new ServerMove;
        $move->forceFill([
            'server_id' => $server->id,
            'user_id' => $actor->id,
            'kind' => $kind,
            'status' => ServerMoveStatus::Queued,
            'source_location_id' => $server->location_id,
            'source_cluster_id' => $server->cluster_id,
            'source_node_id' => $server->node_id,
            'source_storage_id' => $server->storage_id,
            'source_vmid' => $server->vmid,
            'target_location_id' => $location->id,
            'target_cluster_id' => $placement->node->cluster_id,
            'target_node_id' => $placement->node->id,
            'target_storage_id' => $placement->storage->id,
            'target_vmid' => $vmid,
        ])->save();

        return $move;
    }

    /**
     * Run $work under the provisioning locks of these locations.
     *
     * @template T
     *
     * @param  list<int>  $locations  ascending
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(array $locations, callable $work): mixed
    {
        $location = array_shift($locations);

        if ($location === null) {
            return $work();
        }

        return Cache::lock("provisioning:location:{$location}", 30)->block(15, fn () => $this->locked($locations, $work));
    }
}
