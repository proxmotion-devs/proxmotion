<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Servers\GuestScripts;
use App\Enums\PowerAction;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Provisioning\Pipelines\AppRunPipeline;
use App\Provisioning\Pipelines\ChangeResourcesPipeline;
use App\Provisioning\Pipelines\CreateServerPipeline;
use App\Provisioning\Pipelines\HostnamePipeline;
use App\Provisioning\Pipelines\InstallAppPipeline;
use App\Provisioning\Pipelines\PowerPipeline;
use App\Provisioning\Pipelines\PrimaryAddressPipeline;
use App\Provisioning\Pipelines\ReinstallServerPipeline;
use App\Provisioning\Pipelines\ResetPasswordPipeline;
use App\Provisioning\Pipelines\TerminateServerPipeline;
use App\Provisioning\TaskFailer;
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

    /** Seconds a task may run before the worker stops it (and an administrator may end it). */
    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public function __construct(public Task $task) {}

    public function handle(CreateServerPipeline $create, PowerPipeline $power, TerminateServerPipeline $terminate, ResetPasswordPipeline $resetPassword, ReinstallServerPipeline $reinstall, ChangeResourcesPipeline $changeResources, GuestScripts $guestScripts, PrimaryAddressPipeline $primaryAddress, HostnamePipeline $hostname, InstallAppPipeline $installApp, AppRunPipeline $appRun, TaskFailer $failer): void
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
                TaskType::ChangeResources => $changeResources->run($task),
                TaskType::GuestScript => $guestScripts->runOnce($task),
                TaskType::ChangePrimaryAddress => $primaryAddress->run($task),
                TaskType::ChangeHostname => $hostname->run($task),
                TaskType::InstallApp => $installApp->run($task),
                TaskType::AppRun => $appRun->run($task),
            };
        } catch (Throwable $e) {
            $failer->fail($task, $this->messageFor($e));

            return;
        }

        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
        $failer->notify($task);
    }

    /**
     * The job failed outside handle() (it timed out, the worker lost it, the
     * queue's database was locked…): end the task, or the server stays blocked.
     */
    public function failed(?Throwable $e): void
    {
        $task = $this->task->fresh();

        if ($task === null || ! in_array($task->status, [TaskStatus::Queued, TaskStatus::Running], true)) {
            return;
        }

        app(TaskFailer::class)->fail($task, 'The queue job of this task failed ('.($e === null ? 'unknown error' : class_basename($e)).'). See the failed jobs (php artisan queue:failed).');
    }

    /**
     * Proxmox messages are useful to admins; anything else is a bug we log.
     */
    private function messageFor(Throwable $e): string
    {
        if ($e instanceof ProxmoxException) {
            return $e->getMessage();
        }

        report($e);

        return 'Unexpected error ('.class_basename($e).'). See the application log.';
    }
}
