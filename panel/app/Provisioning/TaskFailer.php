<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Domain\Notifications\AdminNotifier;
use App\Domain\Webhooks\Webhooks;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Jobs\StopProxmoxTask;
use App\Models\Task;
use Throwable;

/**
 * Ends a task as failed: by its pipeline, when its queue job failed, or by an
 * administrator for a task that is stuck. A queued or running task blocks
 * every other operation on its server (TaskRunner), so it must always end.
 */
final class TaskFailer
{
    /** Tasks an administrator may cancel while they run (canCancel()). */
    private const array CANCELLABLE = [TaskType::GuestScript, TaskType::CreateBackup];

    public function __construct(
        private readonly Webhooks $webhooks,
        private readonly AdminNotifier $notifier,
    ) {}

    public function fail(Task $task, string $message): void
    {
        // The pipeline may have failed while saving these models: drop what it
        // left unsaved, or the same error would stop the task from ending.
        $task->discardChanges();

        // These leave the VM half-built or half-deleted; the others leave it as it was.
        // The server goes first: should this fail, the task is still open and
        // RunServerTask::failed() ends both.
        if (in_array($task->type, [TaskType::Create, TaskType::Terminate, TaskType::Reinstall], true)) {
            $task->server->discardChanges()->forceFill(['status' => ServerStatus::Error])->save();
        }

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'error' => $message,
            'finished_at' => now(),
        ])->save();

        // The server stays as it is; the move says how far it got (docs/adr/0037 §7).
        if ($task->type === TaskType::Move) {
            $task->move?->markFailed($message);
        }

        $this->notify($task);
        $this->notifier->taskFailed($task);
    }

    /**
     * Cancel an administrator's run of a script or a backup (see
     * canCancel()). The task ends only if it is still queued or running in
     * the database, so a run that has just finished, or a job that has just
     * started it, keeps its outcome. No "task failed" email: an
     * administrator ended it on purpose. A backup's vzdump is stopped in
     * Proxmox (StopProxmoxTask); a script stops through the job running it.
     *
     * @return bool whether it was cancelled
     */
    public function cancel(Task $task, string $message): bool
    {
        $cancelled = Task::query()
            ->whereKey($task->id)
            ->whereIn('type', self::CANCELLABLE)
            ->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])
            ->update(['status' => TaskStatus::Failed, 'error' => $message, 'finished_at' => now()]);

        if ($cancelled === 0) {
            return false;
        }

        $task->refresh();
        $upid = $task->stageValue('upid');

        if ($task->type === TaskType::CreateBackup && is_string($upid)) {
            StopProxmoxTask::dispatch($task->server->cluster_id, $upid);
        }

        $this->notify($task);

        return true;
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
     * An administrator may end a task that is queued, or running without
     * progress for longer than a job may run (the worker died or lost it), or
     * past its deadline (a staged task, docs/adr/0032). Each run of a task
     * records a heartbeat, a staged task also after each stage.
     */
    public function canAbandon(Task $task): bool
    {
        if ($task->status === TaskStatus::Queued) {
            return true;
        }

        if ($task->status !== TaskStatus::Running) {
            return false;
        }

        $progress = $task->heartbeat_at ?? $task->started_at;

        return $progress === null
            || $progress->lt(now()->subSeconds(RunServerTask::TIMEOUT))
            || ($task->deadline_at !== null && $task->deadline_at->isPast());
    }

    /**
     * An administrator may cancel their run of a script (a guest_script task)
     * or a backup while it is queued or running: the task ends at once, and
     * the script is stopped inside the server (GuestScripts), the backup in
     * Proxmox (docs/adr/0051 §4).
     */
    public function canCancel(Task $task): bool
    {
        return in_array($task->type, self::CANCELLABLE, true)
            && in_array($task->status, [TaskStatus::Queued, TaskStatus::Running], true);
    }
}
