<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a server used in one hour or day (docs/adr/0050): averages of the
 * samples within it and the bytes received and sent. Hours are kept
 * KEEP_HOUR_DAYS, days KEEP_DAY_DAYS. Only UsageRollups writes them.
 *
 * @property int $id
 * @property int $server_id
 * @property string $resolution hour or day
 * @property Carbon $starts_at in the panel's time zone
 * @property int $samples
 * @property float|null $cpu_percent
 * @property float|null $memory_percent
 * @property float|null $net_in_rate bytes per second
 * @property float|null $net_out_rate
 * @property float|null $disk_read_rate
 * @property float|null $disk_write_rate
 * @property int $in_bytes received by the server
 * @property int $out_bytes sent by the server
 * @property-read Server $server
 */
class UsageRollup extends Model
{
    use MassPrunable;

    public const string HOUR = 'hour';

    public const string DAY = 'day';

    public const int KEEP_HOUR_DAYS = 35;

    public const int KEEP_DAY_DAYS = 400;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'samples' => 'integer',
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'net_in_rate' => 'float',
            'net_out_rate' => 'float',
            'disk_read_rate' => 'float',
            'disk_write_rate' => 'float',
            'in_bytes' => 'integer',
            'out_bytes' => 'integer',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('resolution', self::HOUR)->where('starts_at', '<', now()->subDays(self::KEEP_HOUR_DAYS)))
            ->orWhere(fn (Builder $query) => $query->where('resolution', self::DAY)->where('starts_at', '<', now()->subDays(self::KEEP_DAY_DAYS)));
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
