<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClusterType;
use App\Enums\NodeStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\NodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Proxmox node. Created and updated by the cluster sync; admins only
 * change the placement settings (maintenance, over-commit, limits).
 *
 * @property int $id
 * @property string $uuid
 * @property int $cluster_id
 * @property string $name
 * @property string|null $address its address in /cluster/status, from the sync
 * @property string|null $api_host where its API answers, set by an administrator; overrides $address (docs/adr/0037 §5)
 * @property NodeStatus $status
 * @property bool $is_maintenance
 * @property int|null $cpu_cores
 * @property int|null $memory_bytes
 * @property string $cpu_overcommit
 * @property string $memory_overcommit
 * @property string $disk_overcommit
 * @property int|null $max_servers
 * @property Carbon|null $last_seen_at
 * @property string|null $disk_health_error why its disks and pools could not be read at the last sync (docs/adr/0047)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cluster $cluster
 */
#[Fillable(['is_maintenance', 'cpu_overcommit', 'memory_overcommit', 'disk_overcommit', 'max_servers', 'api_host'])]
class Node extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NodeStatus::class,
            'is_maintenance' => 'boolean',
            'cpu_cores' => 'integer',
            'memory_bytes' => 'integer',
            'cpu_overcommit' => 'decimal:2',
            'memory_overcommit' => 'decimal:2',
            'disk_overcommit' => 'decimal:2',
            'max_servers' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Where its API answers: what an administrator set, else its address in
     * the cluster, else the cluster's first host for a standalone node. A
     * server moved in from another cluster lands on the node behind this
     * address (docs/adr/0037 §5).
     */
    public function apiHost(): ?string
    {
        $host = $this->api_host ?? $this->address;

        if ($host === null && $this->cluster->type === ClusterType::Standalone) {
            return $this->cluster->hosts[0] ?? null;
        }

        return $host;
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * @return HasMany<Storage, $this>
     */
    public function storages(): HasMany
    {
        return $this->hasMany(Storage::class);
    }

    /**
     * @return HasMany<NodeDisk, $this>
     */
    public function disks(): HasMany
    {
        return $this->hasMany(NodeDisk::class);
    }

    /**
     * @return HasMany<NetworkBridge, $this>
     */
    public function bridges(): HasMany
    {
        return $this->hasMany(NetworkBridge::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return BelongsToMany<PlacementGroup, $this>
     */
    public function placementGroups(): BelongsToMany
    {
        return $this->belongsToMany(PlacementGroup::class);
    }
}
