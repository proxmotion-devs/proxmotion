<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;

/**
 * Creates a Task for a server operation and queues it.
 */
final class TaskRunner
{
    /**
     * @throws ProvisioningException when another task is still running for the server
     */
    public function start(Server $server, TaskType $type, ?User $actor = null): Task
    {
        $running = $server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists();

        if ($running) {
            throw new ProvisioningException('Another task is still running for this server.');
        }

        $task = new Task;
        $task->forceFill([
            'server_id' => $server->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'status' => TaskStatus::Queued,
            'steps' => [],
        ])->save();

        $status = match ($type) {
            TaskType::Create => ServerStatus::Pending,
            TaskType::Terminate => ServerStatus::Terminating,
            default => null,
        };

        if ($status !== null) {
            $server->forceFill(['status' => $status])->save();
        }

        RunServerTask::dispatch($task);

        return $task;
    }
}
