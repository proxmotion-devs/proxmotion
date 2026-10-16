<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Provisioning\Pipelines\CreateServerPipeline;
use App\Provisioning\Pipelines\PowerPipeline;
use App\Provisioning\Pipelines\ReinstallServerPipeline;
use App\Provisioning\Pipelines\ResetPasswordPipeline;
use App\Provisioning\Pipelines\TerminateServerPipeline;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one server task. Pipelines are idempotent, so a failed task can simply
 * be started again and continues where Proxmox's state says it stopped.
 */
class RunServerTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public Task $task) {}

    public function handle(CreateServerPipeline $create, PowerPipeline $power, TerminateServerPipeline $terminate, ResetPasswordPipeline $resetPassword, ReinstallServerPipeline $reinstall): void
    {
        $task = $this->task;

        if ($task->status !== TaskStatus::Queued) {
            return;
        }

        $task->forceFill(['status' => TaskStatus::Running, 'started_at' => now()])->save();

        try {
            match ($task->type) {
                TaskType::Create => $create->run($task),
                TaskType::Suspend => $power->suspend($task),
                TaskType::Unsuspend => $power->unsuspend($task),
                TaskType::Terminate => $terminate->run($task),
                TaskType::Adopt => null,
                TaskType::Start, TaskType::Shutdown, TaskType::Stop, TaskType::Reboot => $power->power($task, PowerAction::from($task->type->value)),
                TaskType::ResetPassword => $resetPassword->run($task),
                TaskType::Reinstall => $reinstall->run($task),
            };
        } catch (Throwable $e) {
            $this->fail($task, $e);

            return;
        }

        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
    }

    private function fail(Task $task, Throwable $e): void
    {
        // Proxmox messages are useful to admins; anything else is a bug we log.
        $message = $e instanceof ProxmoxException ? $e->getMessage() : 'Unexpected error ('.class_basename($e).'). See the application log.';

        if (! $e instanceof ProxmoxException) {
            report($e);
        }

        $task->forceFill([
            'status' => TaskStatus::Failed,
            'error' => $message,
            'finished_at' => now(),
        ])->save();

        // These leave the VM half-built or half-deleted; the others leave it as it was.
        if (in_array($task->type, [TaskType::Create, TaskType::Terminate, TaskType::Reinstall], true)) {
            $task->server->forceFill(['status' => ServerStatus::Error])->save();
        }
    }
}
