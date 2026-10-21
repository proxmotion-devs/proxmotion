<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ip_pool_id
 * @property int|null $server_id
 * @property int|null $server_move_id held for a move to another location until it is recorded (docs/adr/0037 §3)
 * @property bool $is_primary the server's primary address of its version (docs/adr/0011); written only by provisioning
 * @property Carbon|null $assigned_at
 * @property string $address
 * @property string|null $mac_address
 * @property bool $is_reserved
 * @property string|null $notes
 * @property string|null $ptr reverse DNS name requested for the address (docs/adr/0003)
 * @property Carbon|null $ptr_synced_at when the driver published the current PTR; null while pending
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IpPool $pool
 * @property-read Server|null $server
 */
#[Fillable(['address', 'mac_address', 'is_reserved', 'notes'])]
class IpAddress extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_reserved' => 'boolean',
            'is_primary' => 'boolean',
            'assigned_at' => 'datetime',
            'ptr_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<IpPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }
}
