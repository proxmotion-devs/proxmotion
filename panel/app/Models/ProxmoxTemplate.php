<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A VM template that exists on a cluster. Read-only: maintained by the cluster sync.
 *
 * @property int $id
 * @property int $cluster_id
 * @property int $node_id
 * @property int $vmid
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cluster $cluster
 * @property-read Node $node
 */
class ProxmoxTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vmid' => 'integer',
        ];
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
     * @return BelongsToMany<OsTemplate, $this>
     */
    public function osTemplates(): BelongsToMany
    {
        return $this->belongsToMany(OsTemplate::class);
    }
}
