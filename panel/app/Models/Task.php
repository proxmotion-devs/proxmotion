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
 * @property int|null $api_token_id
 * @property TaskType $type
 * @property TaskStatus $status
 * @property list<array{key: string, status: string, message: string|null, finished_at: string|null}> $steps
 * @property array<string, mixed>|null $input plain inputs of the task, e.g. addresses to release
 * @property string|null $secret encrypted input of the task (a chosen root password); cleared once used
 * @property string|null $stage the stage a staged task (create, reinstall) is in (docs/adr/0032)
 * @property array<string, mixed>|null $stage_state what the current stage remembers between runs
 * @property int $dispatch how often the task was queued again; older jobs of it do nothing
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $heartbeat_at when a run of the task last made progress
 * @property Carbon|null $deadline_at when a staged task fails if it has not finished
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class Task extends Model
{
    use HasPublicUuid;

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'steps' => 'array',
            'secret' => 'encrypted',
            'input' => 'array',
            'stage_state' => 'array',
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'deadline_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Record that a slow step started, so progress shows what is happening.
     * completeStep() replaces the entry.
     */
    public function startStep(string $key): void
    {
        $steps = array_values(array_filter($this->steps, fn (array $step) => $step['key'] !== $key));
        $steps[] = ['key' => $key, 'status' => 'running', 'message' => null, 'finished_at' => null];

        $this->forceFill(['steps' => $steps])->save();
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
     * Something the current stage remembers between runs (docs/adr/0032).
     */
    public function stageValue(string $key): mixed
    {
        return $this->stage_state[$key] ?? null;
    }

    /**
     * Remember values for the current stage (null forgets one) and save.
     *
     * @param  array<string, mixed>  $values
     */
    public function rememberForStage(array $values): void
    {
        $state = array_filter([...$this->stage_state ?? [], ...$values], fn (mixed $value) => $value !== null);

        $this->forceFill(['stage_state' => $state === [] ? null : $state])->save();
    }

    /**
     * Keep a plain input for later stages (null removes it) and save.
     */
    public function rememberInput(string $key, mixed $value): void
    {
        $input = $this->input ?? [];

        if ($value === null) {
            unset($input[$key]);
        } else {
            $input[$key] = $value;
        }

        $this->forceFill(['input' => $input === [] ? null : $input])->save();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
