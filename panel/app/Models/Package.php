<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NetworkModel;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A VPS plan. Units: memory and disk in MiB, traffic in bytes, port speed in Mbps.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property int $cpu_cores
 * @property int $memory_mib
 * @property int $disk_mib
 * @property int|null $traffic_bytes
 * @property int|null $port_speed_mbps
 * @property string|null $cpu_limit at most this many cores' worth of CPU time, e.g. "1.50" (docs/adr/0034); null: unlimited
 * @property int|null $cpu_units relative CPU weight on a busy node
 * @property int|null $disk_mb_s read and write throughput of the boot disk, MB/s each
 * @property int|null $disk_iops read and write operations per second of the boot disk, each
 * @property int|null $firewall_ruleset_id new servers start with these rules and its default policy (docs/adr/0035 §3)
 * @property int $ipv4_count
 * @property int $ipv6_count
 * @property int $snapshot_slots
 * @property int $backup_slots
 * @property Collection<int, NetworkModel> $network_models
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PlacementGroup> $placementGroups
 */
#[Fillable([
    'name', 'cpu_cores', 'memory_mib', 'disk_mib', 'traffic_bytes', 'port_speed_mbps', 'cpu_limit', 'cpu_units', 'disk_mb_s', 'disk_iops', 'firewall_ruleset_id',
    'ipv4_count', 'ipv6_count', 'snapshot_slots', 'backup_slots', 'network_models', 'is_active', 'sort_order',
])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpu_cores' => 'integer',
            'memory_mib' => 'integer',
            'disk_mib' => 'integer',
            'traffic_bytes' => 'integer',
            'port_speed_mbps' => 'integer',
            'cpu_limit' => 'decimal:2',
            'cpu_units' => 'integer',
            'disk_mb_s' => 'integer',
            'disk_iops' => 'integer',
            'ipv4_count' => 'integer',
            'ipv6_count' => 'integer',
            'snapshot_slots' => 'integer',
            'backup_slots' => 'integer',
            'network_models' => AsEnumCollection::of(NetworkModel::class),
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The placement groups the package's servers go to (e.g. to keep plans on
     * one CPU type); none means any node (docs/adr/0020). Only the groups of
     * the ordered location count.
     *
     * @return BelongsToMany<PlacementGroup, $this>
     */
    public function placementGroups(): BelongsToMany
    {
        return $this->belongsToMany(PlacementGroup::class);
    }

    /**
     * @return BelongsTo<FirewallRuleset, $this>
     */
    public function firewallRuleset(): BelongsTo
    {
        return $this->belongsTo(FirewallRuleset::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
