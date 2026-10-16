<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NetworkModel;
use App\Enums\ServerOrigin;
use App\Enums\ServerStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A customer VPS. The customer lives in the billing system; the server only
 * keeps its ids (billing system, external_id, external_client_id). Its resources are copied from the package (or entered as custom
 * resources) at creation time, so later package edits do not change existing servers.
 *
 * Nothing on this model is mass-assignable: servers are only created and
 * changed by the provisioning code (forceFill).
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $package_id
 * @property int $location_id
 * @property int $cluster_id
 * @property int $node_id
 * @property int|null $storage_id
 * @property int|null $os_template_id
 * @property int $vmid
 * @property string $hostname
 * @property ServerStatus $status
 * @property ServerOrigin $origin
 * @property NetworkModel $network_model
 * @property int $cpu_cores
 * @property int $memory_mib
 * @property int $disk_mib
 * @property int|null $port_speed_mbps
 * @property int|null $traffic_bytes
 * @property string|null $root_password
 * @property bool $root_password_unrevealed the billing system has not fetched the current root password yet
 * @property string|null $ssh_keys
 * @property string|null $suspension_reason
 * @property int|null $billing_system_id the billing system that owns the server; only its API tokens see it
 * @property string|null $external_id billing service id, unique within the billing system
 * @property string|null $external_client_id billing customer id
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $terminated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Package|null $package
 * @property-read BillingSystem|null $billingSystem
 * @property-read Location $location
 * @property-read Cluster $cluster
 * @property-read Node $node
 * @property-read Storage|null $storage
 * @property-read OsTemplate|null $osTemplate
 */
#[Hidden(['root_password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vmid' => 'integer',
            'status' => ServerStatus::class,
            'origin' => ServerOrigin::class,
            'network_model' => NetworkModel::class,
            'cpu_cores' => 'integer',
            'memory_mib' => 'integer',
            'disk_mib' => 'integer',
            'port_speed_mbps' => 'integer',
            'traffic_bytes' => 'integer',
            'root_password' => 'encrypted',
            'root_password_unrevealed' => 'boolean',
            'provisioned_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOccupying(Builder $query): void
    {
        $query->whereIn('status', ServerStatus::occupying());
    }

    /**
     * @return BelongsTo<BillingSystem, $this>
     */
    public function billingSystem(): BelongsTo
    {
        return $this->belongsTo(BillingSystem::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return BelongsTo<Storage, $this>
     */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(Storage::class);
    }

    /**
     * @return BelongsTo<OsTemplate, $this>
     */
    public function osTemplate(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class);
    }

    /**
     * @return HasMany<IpAddress, $this>
     */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<ServerDrift, $this>
     */
    public function drifts(): HasMany
    {
        return $this->hasMany(ServerDrift::class);
    }

    /**
     * @return HasOne<Task, $this>
     */
    public function latestTask(): HasOne
    {
        return $this->hasOne(Task::class)->latestOfMany();
    }
}
