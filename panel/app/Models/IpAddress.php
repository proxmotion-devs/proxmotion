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
 * @property string $address
 * @property string|null $mac_address
 * @property bool $is_reserved
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read IpPool $pool
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
        ];
    }

    /**
     * @return BelongsTo<IpPool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }
}
