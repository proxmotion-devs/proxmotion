<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Webhooks\Webhooks;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\Task;
use Throwable;

/**
 * Ends a task as failed: by its pipeline, when its queue job failed, or by an
 * administrator for a task that is stuck. A queued or running task blocks
 * every other operation on its server (TaskRunner), so it must always end.
 */
final class TaskFailer
{
    public function __construct(private readonly Webhooks $webhooks) {}

    public function fail(Task $task, string $message): void
    {
        $task->forceFill([
            'status' => TaskStatus::Failed,
            'error' => $message,
            'finished_at' => now(),
        ])->save();

        // These leave the VM half-built or half-deleted; the others leave it as it was.
        if (in_array($task->type, [TaskType::Create, TaskType::Terminate, TaskType::Reinstall], true)) {
            $task->server->forceFill(['status' => ServerStatus::Error])->save();
        }

        $this->notify($task);
    }

    /**
     * Tell the billing system that the task finished (docs/adr/0013). A
     * webhook problem never changes the task's outcome.
     */
    public function notify(Task $task): void
    {
        try {
            $this->webhooks->taskFinished($task);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * An administrator may end a task that is queued, or running for longer
     * than its job may run (the worker died or lost it).
     */
    public function canAbandon(Task $task): bool
    {
        return match ($task->status) {
            TaskStatus::Queued => true,
            TaskStatus::Running => $task->started_at === null || $task->started_at->lt(now()->subSeconds(RunServerTask::TIMEOUT)),
            default => false,
        };
    }
}
