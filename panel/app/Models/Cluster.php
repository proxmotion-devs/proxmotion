<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClusterType;
use App\Models\Concerns\HasPublicUuid;
use App\Proxmox\ProxmoxConnection;
use Database\Factories\ClusterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Proxmox API connection: a cluster or a standalone node.
 *
 * @property int $id
 * @property string $uuid
 * @property int $location_id
 * @property string $name
 * @property ClusterType|null $type
 * @property string|null $proxmox_cluster_name
 * @property list<string> $hosts
 * @property int $port
 * @property string $token_id
 * @property string $token_secret
 * @property bool $verify_tls
 * @property bool $is_active
 * @property string|null $pve_version
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
#[Fillable(['location_id', 'name', 'hosts', 'port', 'token_id', 'token_secret', 'verify_tls', 'resource_pool', 'is_active'])]
#[Hidden(['token_secret'])]
class Cluster extends Model
{
    /** @use HasFactory<ClusterFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ClusterType::class,
            'hosts' => 'array',
            'port' => 'integer',
            'token_secret' => 'encrypted',
            'verify_tls' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): ProxmoxConnection
    {
        return new ProxmoxConnection(
            hosts: $this->hosts,
            port: $this->port,
            tokenId: $this->token_id,
            tokenSecret: $this->token_secret,
            verifyTls: $this->verify_tls,
        );
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /**
     * @return HasMany<ProxmoxTemplate, $this>
     */
    public function proxmoxTemplates(): HasMany
    {
        return $this->hasMany(ProxmoxTemplate::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
