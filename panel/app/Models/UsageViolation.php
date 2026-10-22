<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server over its traffic quota or a usage limit (docs/adr/0039), open
 * until it no longer is. Its action is taken once (acted_at). Only
 * UsageLimits writes them; resolved ones are pruned after KEEP_DAYS.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property UsageKind $kind
 * @property Carbon|null $period_start the traffic period of a traffic violation
 * @property float $value counted bytes (traffic), else the average over the window in the kind's unit
 * @property float $threshold the quota or limit, same unit
 * @property int|null $window_minutes
 * @property UsageAction $action what the settings said to do when it opened
 * @property Carbon|null $acted_at when the action's task was started
 * @property int|null $task_id
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Task|null $task
 */
class UsageViolation extends Model
{
    use HasPublicUuid, MassPrunable;

    public const int KEEP_DAYS = 90;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => UsageKind::class,
            'action' => UsageAction::class,
            'period_start' => 'date',
            'value' => 'float',
            'threshold' => 'float',
            'window_minutes' => 'integer',
            'acted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * The shape billing systems get (webhook server.usage_exceeded) and administrators see.
     *
     * @return array{id: string, kind: string, value: float, threshold: float, unit: string, window_minutes: int|null, period_start: string|null, action: string, started_at: string|null, acted_at: string|null, resolved_at: string|null}
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->uuid,
            'kind' => $this->kind->value,
            'value' => $this->value,
            'threshold' => $this->threshold,
            'unit' => $this->kind->unit(),
            'window_minutes' => $this->window_minutes,
            'period_start' => $this->period_start?->toDateString(),
            'action' => $this->action->value,
            'started_at' => $this->created_at?->toIso8601String(),
            'acted_at' => $this->acted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
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
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
