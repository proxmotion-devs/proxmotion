<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\ApiToken;
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
    public function start(Server $server, TaskType $type, User|ApiToken|null $actor = null): Task
    {
        $running = $server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists();

        if ($running) {
            throw new ProvisioningException('Another task is still running for this server.');
        }

        $task = new Task;
        $task->forceFill([
            'server_id' => $server->id,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'api_token_id' => $actor instanceof ApiToken ? $actor->id : null,
            'type' => $type,
            'status' => TaskStatus::Queued,
            'steps' => [],
        ])->save();

        $status = match ($type) {
            TaskType::Create => ServerStatus::Pending,
            TaskType::Terminate => ServerStatus::Terminating,
            TaskType::Reinstall => ServerStatus::Reinstalling,
            default => null,
        };

        if ($status !== null) {
            $server->forceFill(['status' => $status])->save();
        }

        // Inside a transaction, a worker must not pick the task up before the data it needs is committed.
        RunServerTask::dispatch($task)->afterCommit();

        return $task;
    }
}
