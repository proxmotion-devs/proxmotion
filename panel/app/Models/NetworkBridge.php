<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Linux/OVS bridge on a node. Read-only: maintained by the cluster sync.
 *
 * @property int $id
 * @property int $node_id
 * @property string $name
 * @property bool $is_active
 * @property bool $is_vlan_aware
 * @property string|null $cidr
 * @property string|null $comments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Node $node
 */
class NetworkBridge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_vlan_aware' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
