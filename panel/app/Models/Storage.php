<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StorageHealth;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Proxmox storage as seen from one node (shared storages appear once per node).
 *
 * @phpstan-type HealthProblem array{severity: string, message: string, replace: array<string, string|int>}
 *
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property string $type
 * @property list<string> $content
 * @property bool $is_shared
 * @property bool $is_available
 * @property bool $use_for_servers
 * @property int|null $total_bytes
 * @property int|null $used_bytes
 * @property StorageHealth $health the worst of its problems at the last sync (docs/adr/0047)
 * @property list<HealthProblem>|null $health_problems what is wrong: English keys with their placeholders
 * @property string|null $pool_state the state of its ZFS pool (ONLINE, DEGRADED…) or of the Ceph cluster (HEALTH_OK…)
 * @property int|null $metadata_used_bytes LVM thin pools: metadata in use
 * @property int|null $metadata_total_bytes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
#[Fillable(['use_for_servers'])]
class Storage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_shared' => 'boolean',
            'is_available' => 'boolean',
            'use_for_servers' => 'boolean',
            'total_bytes' => 'integer',
            'used_bytes' => 'integer',
            'health' => StorageHealth::class,
            'health_problems' => 'array',
            'metadata_used_bytes' => 'integer',
            'metadata_total_bytes' => 'integer',
        ];
    }

    public function supportsVmDisks(): bool
    {
        return in_array('images', $this->content, true);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
