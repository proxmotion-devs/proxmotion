<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Usage\TrafficPeriods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server's traffic in one month (docs/adr/0039), from the traffic reset day
 * on. Only UsageCollector writes it.
 *
 * @property int $id
 * @property int $server_id
 * @property Carbon $period_start
 * @property int $in_bytes received by the server
 * @property int $out_bytes sent by the server
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class TrafficPeriod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'in_bytes' => 'integer',
            'out_bytes' => 'integer',
        ];
    }

    /**
     * The bytes that count against the quota (Panel settings).
     */
    public function counted(): int
    {
        return TrafficPeriods::counted($this->in_bytes, $this->out_bytes);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
