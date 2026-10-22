<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\PanelSettings;
use App\Enums\DriftType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One difference between the panel and Proxmox, from first seen until it is
 * gone (resolved by the sync) or handled by an admin. Only the drift detector
 * and the admin actions write it; nothing is mass-assignable.
 *
 * @property int $id
 * @property string $uuid
 * @property int $cluster_id
 * @property int|null $server_id
 * @property int $vmid
 * @property DriftType $type
 * @property array<string, mixed>|null $expected
 * @property array<string, mixed>|null $actual
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cluster $cluster
 * @property-read Server|null $server
 */
class ServerDrift extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DriftType::class,
            'vmid' => 'integer',
            'expected' => 'array',
            'actual' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Open drifts admins should see: a stopped VM of an active server only
     * once it has been stopped for the grace period (customers shut down too).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $cutoff = now()->subMinutes((int) PanelSettings::get('drift_stopped_grace_minutes'));

        $query->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->where(fn (Builder $query) => $query
                ->where('type', '!=', DriftType::PowerMismatch)
                ->orWhere('expected->status', '!=', 'running')
                ->orWhere('first_seen_at', '<=', $cutoff));
    }

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function resolve(string $resolution, ?User $by = null): void
    {
        $this->forceFill(['resolved_at' => now(), 'resolution' => $resolution, 'resolved_by' => $by?->id])->save();
    }
}
