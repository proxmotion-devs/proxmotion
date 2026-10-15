<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A long-running operation on a server (create, suspend, terminate…) and its
 * step-by-step progress. Steps are recorded so an interrupted run can be
 * inspected and resumed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $user_id
 * @property TaskType $type
 * @property TaskStatus $status
 * @property list<array{key: string, status: string, message: string|null, finished_at: string|null}> $steps
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class Task extends Model
{
    use HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Record that a step finished (or was skipped because it was already done).
     */
    public function completeStep(string $key, ?string $message = null): void
    {
        $steps = array_values(array_filter($this->steps, fn (array $step) => $step['key'] !== $key));
        $steps[] = ['key' => $key, 'status' => 'done', 'message' => $message, 'finished_at' => now()->toIso8601String()];

        $this->forceFill(['steps' => $steps])->save();
    }

    public function hasCompleted(string $key): bool
    {
        foreach ($this->steps as $step) {
            if ($step['key'] === $key && $step['status'] === 'done') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
