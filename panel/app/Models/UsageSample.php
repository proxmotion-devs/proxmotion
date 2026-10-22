<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one collection (every 5 minutes) saw of a server (docs/adr/0039):
 * CPU and memory in per cent at that moment, network and disk in bytes per
 * second since the previous collection. Kept KEEP_HOURS for the usage
 * limits' windows. Only UsageCollector writes them.
 *
 * @property int $id
 * @property int $server_id
 * @property Carbon $sampled_at
 * @property string $status the VM's status (running, stopped, …)
 * @property float|null $cpu_percent
 * @property float|null $memory_percent
 * @property float|null $net_in_rate
 * @property float|null $net_out_rate
 * @property float|null $disk_read_rate
 * @property float|null $disk_write_rate
 * @property-read Server $server
 */
class UsageSample extends Model
{
    use MassPrunable;

    public const int KEEP_HOURS = 48;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'net_in_rate' => 'float',
            'net_out_rate' => 'float',
            'disk_read_rate' => 'float',
            'disk_write_rate' => 'float',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('sampled_at', '<', now()->subHours(self::KEEP_HOURS));
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
