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
 * @property string|null $incoming_token_id the token servers from other clusters are moved in with (docs/adr/0037 §5)
 * @property string|null $incoming_token_secret
 * @property bool $verify_tls
 * @property string|null $tls_fingerprint SHA-256 fingerprint of its certificate, for moves into it when the certificate is not publicly trusted
 * @property bool $is_active
 * @property string|null $pve_version
 * @property Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property bool|null $firewall_enabled the datacenter firewall's `enable` option at the last sync; null: not read yet
 * @property bool|null $firewall_ebtables the datacenter firewall's `ebtables` option (ARP filtering) at the last sync
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
#[Fillable(['location_id', 'name', 'hosts', 'port', 'token_id', 'token_secret', 'verify_tls', 'resource_pool', 'is_active'])]
#[Hidden(['token_secret', 'incoming_token_secret'])]
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
            'incoming_token_secret' => 'encrypted',
            'verify_tls' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'firewall_enabled' => 'boolean',
            'firewall_ebtables' => 'boolean',
        ];
    }

    /**
     * Why the VM firewalls of this cluster cannot stop spoofing, or null when
     * they can (or the sync has not read the options yet). docs/adr/0033
     */
    public function guestFirewallProblem(): ?string
    {
        return match (true) {
            $this->firewall_enabled === false => 'The datacenter firewall is off, so the anti-spoofing filters of its servers do nothing.',
            $this->firewall_ebtables === false => 'ebtables is off in the datacenter firewall, so its servers can send ARP replies for any address.',
            default => null,
        };
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
     * Whether servers can be moved into it from other clusters (docs/adr/0037 §5).
     */
    public function receivesMoves(): bool
    {
        return $this->incoming_token_id !== null && $this->incoming_token_secret !== null;
    }

    /**
     * The connection of its incoming move token: the panel adds moved VMs to
     * its resource pool with it, and the source cluster migrates into it with
     * it (RemoteEndpoint). Null without one.
     */
    public function incomingConnection(): ?ProxmoxConnection
    {
        if (! $this->receivesMoves()) {
            return null;
        }

        return new ProxmoxConnection(
            hosts: $this->hosts,
            port: $this->port,
            tokenId: (string) $this->incoming_token_id,
            tokenSecret: (string) $this->incoming_token_secret,
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
