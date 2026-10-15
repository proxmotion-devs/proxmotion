<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Proxmox storage as seen from one node (shared storages appear once per node).
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
