<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServerMoveKind;
use App\Enums\ServerMoveStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One move of a server to another node, cluster or location (docs/adr/0037),
 * kept as its history. While it runs it reserves the target VMID and, for a
 * move to another location, the new addresses (ip_addresses.server_move_id).
 *
 * Nothing is mass-assignable: only ServerMover and the move pipeline write it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $user_id
 * @property ServerMoveKind $kind
 * @property ServerMoveStatus $status
 * @property int $source_location_id
 * @property int $source_cluster_id
 * @property int $source_node_id
 * @property int|null $source_storage_id
 * @property int $source_vmid
 * @property int $target_location_id
 * @property int $target_cluster_id
 * @property int $target_node_id
 * @property int|null $target_storage_id
 * @property int $target_vmid
 * @property bool|null $was_running whether the VM ran when the move began; null until it is known
 * @property Carbon|null $migrated_at when Proxmox finished moving the VM
 * @property Carbon|null $recorded_at when the server was recorded at the target
 * @property Carbon|null $finished_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read User|null $user
 * @property-read Location $sourceLocation
 * @property-read Cluster $sourceCluster
 * @property-read Node $sourceNode
 * @property-read Storage|null $sourceStorage
 * @property-read Location $targetLocation
 * @property-read Cluster $targetCluster
 * @property-read Node $targetNode
 * @property-read Storage|null $targetStorage
 */
class ServerMove extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ServerMoveKind::class,
            'status' => ServerMoveStatus::class,
            'source_vmid' => 'integer',
            'target_vmid' => 'integer',
            'was_running' => 'boolean',
            'migrated_at' => 'datetime',
            'recorded_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * VMIDs of a cluster that moves still hold: the target VMID of a move into
     * it that is not recorded yet. Drift reports and adoption leave them alone,
     * and new servers do not get them.
     *
     * @return list<int>
     */
    public static function heldVmids(Cluster $cluster): array
    {
        return array_values(array_map('intval', self::query()
            ->where('target_cluster_id', $cluster->id)
            ->whereNull('recorded_at')
            ->whereIn('status', [ServerMoveStatus::Queued, ServerMoveStatus::Running])
            ->pluck('target_vmid')
            ->all()));
    }

    /**
     * The move's task failed. Before it was recorded the server is still where
     * it was, so the addresses held for it are free again.
     */
    public function markFailed(string $message): void
    {
        $this->forceFill(['status' => ServerMoveStatus::Failed, 'error' => $message, 'finished_at' => now()])->save();

        if ($this->recorded_at === null) {
            IpAddress::query()->where('server_move_id', $this->id)->update(['server_move_id' => null]);
        }

        AuditLog::record('server.move_failed', $this->user, $this->server, ['hostname' => $this->server->hostname, 'kind' => $this->kind->value, 'error' => $message, 'moved' => $this->migrated_at !== null]);
    }

    /**
     * Whether Proxmox has moved the VM but the panel's own steps did not all
     * finish: a new move task can finish it (docs/adr/0037 §7).
     */
    public function canFinish(): bool
    {
        return $this->status === ServerMoveStatus::Failed && $this->migrated_at !== null;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function sourceCluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'source_cluster_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'source_node_id');
    }

    /**
     * @return BelongsTo<Storage, $this>
     */
    public function sourceStorage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'source_storage_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function targetLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'target_location_id');
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function targetCluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'target_cluster_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'target_node_id');
    }

    /**
     * @return BelongsTo<Storage, $this>
     */
    public function targetStorage(): BelongsTo
    {
        return $this->belongsTo(Storage::class, 'target_storage_id');
    }

    /**
     * The new addresses a move to another location holds until it is recorded.
     *
     * @return HasMany<IpAddress, $this>
     */
    public function reservedAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
