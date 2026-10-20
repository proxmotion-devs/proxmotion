<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerVerifier;
use App\Enums\PowerAction;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Provisioning\Pipelines\AddressesPipeline;
use App\Provisioning\Pipelines\AppRunPipeline;
use App\Provisioning\Pipelines\ChangeResourcesPipeline;
use App\Provisioning\Pipelines\CreateServerPipeline;
use App\Provisioning\Pipelines\HostnamePipeline;
use App\Provisioning\Pipelines\InstallAppPipeline;
use App\Provisioning\Pipelines\PowerPipeline;
use App\Provisioning\Pipelines\ReinstallServerPipeline;
use App\Provisioning\Pipelines\ResetPasswordPipeline;
use App\Provisioning\Pipelines\TerminateServerPipeline;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Provisioning\Stages\TaskDeadlineExceeded;
use App\Provisioning\TaskFailer;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Runs one server task. Pipelines are idempotent, so a failed task can simply
 * be started again and continues where Proxmox's state says it stopped.
 *
 * Creating and reinstalling run in stages (docs/adr/0032): when a stage has
 * to wait, the job queues itself again with that delay ($dispatch counts
 * these runs) and the worker is free in the meantime.
 */
class RunServerTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Seconds a task may run before the worker stops it (and an administrator may end it). */
    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    /**
     * @param  int  $dispatch  0 for the first run; for later runs of a staged task, the task's dispatch count when it was queued
     */
    public function __construct(public Task $task, public int $dispatch = 0) {}

    public function handle(CreateServerPipeline $create, PowerPipeline $power, TerminateServerPipeline $terminate, ResetPasswordPipeline $resetPassword, ReinstallServerPipeline $reinstall, ChangeResourcesPipeline $changeResources, GuestScripts $guestScripts, AddressesPipeline $addresses, HostnamePipeline $hostname, InstallAppPipeline $installApp, AppRunPipeline $appRun, ServerVerifier $verifier, StageRunner $stages, TaskFailer $failer): void
    {
        $task = $this->task;

        if (! $this->isCurrent($task)) {
            return;
        }

        if ($this->dispatch === 0) {
            $task->forceFill(['status' => TaskStatus::Running, 'started_at' => now()]);
        }

        $task->forceFill(['heartbeat_at' => now()])->save();

        try {
            $staged = match ($task->type) {
                TaskType::Create => $create,
                TaskType::Reinstall => $reinstall,
                default => null,
            };

            if ($staged !== null) {
                if (! $this->runStages($task, $staged, $stages)) {
                    return;
                }
            } else {
                match ($task->type) {
                    TaskType::Suspend => $power->suspend($task),
                    TaskType::Unsuspend => $power->unsuspend($task),
                    TaskType::Terminate => $terminate->run($task),
                    TaskType::Adopt => null,
                    TaskType::Start, TaskType::Shutdown, TaskType::Stop, TaskType::Reboot => $power->power($task, PowerAction::from($task->type->value)),
                    TaskType::ResetPassword => $resetPassword->run($task),
                    TaskType::ChangeResources => $changeResources->run($task),
                    TaskType::GuestScript => $guestScripts->runOnce($task),
                    TaskType::ChangePrimaryAddress, TaskType::ChangeAddresses => $addresses->run($task),
                    TaskType::ChangeHostname => $hostname->run($task),
                    TaskType::InstallApp => $installApp->run($task),
                    TaskType::AppRun => $appRun->run($task),
                    TaskType::Verify => $verifier->runTask($task),
                };
            }
        } catch (Throwable $e) {
            // An administrator may have ended the task meanwhile: it stays as they left it.
            if ($task->fresh()?->status === TaskStatus::Running) {
                $failer->fail($task, $this->messageFor($e));
            }

            return;
        }

        if ($task->fresh()?->status !== TaskStatus::Running) {
            return;
        }

        $task->forceFill(['status' => TaskStatus::Succeeded, 'finished_at' => now()])->save();
        $failer->notify($task);
    }

    /**
     * Run stages until all are done (true) or one has to wait: then queue
     * the next run with that delay (false). On the sync queue, and when the
     * job runs outside a queue, sleep and go on in place instead.
     */
    private function runStages(Task $task, StagedPipeline $pipeline, StageRunner $stages): bool
    {
        while (($delay = $stages->advance($task, $pipeline)) !== null) {
            if ($this->job === null || $this->job instanceof SyncJob) {
                Sleep::for($delay)->seconds();

                continue;
            }

            if ($task->fresh()?->status !== TaskStatus::Running) {
                return false;
            }

            $task->increment('dispatch');
            self::dispatch($task, $task->dispatch)->delay($delay);

            return false;
        }

        return true;
    }

    /**
     * Whether this job should run: the first run of a queued task, or the
     * latest run of a staged task that is still running.
     */
    private function isCurrent(Task $task): bool
    {
        return $this->dispatch === 0
            ? $task->status === TaskStatus::Queued
            : $task->status === TaskStatus::Running && $task->dispatch === $this->dispatch;
    }

    /**
     * The job failed outside handle() (it timed out, the worker lost it, the
     * queue's database was locked…): end the task, or the server stays blocked.
     * A job that a later run of its task replaced leaves the task alone.
     */
    public function failed(?Throwable $e): void
    {
        $task = $this->task->fresh();

        if ($task === null || ! in_array($task->status, [TaskStatus::Queued, TaskStatus::Running], true)) {
            return;
        }

        if ($this->dispatch !== $task->dispatch) {
            return;
        }

        app(TaskFailer::class)->fail($task, 'The queue job of this task failed ('.($e === null ? 'unknown error' : class_basename($e)).'). See the failed jobs (php artisan queue:failed).');
    }

    /**
     * Proxmox messages are useful to admins; anything else is a bug we log.
     */
    private function messageFor(Throwable $e): string
    {
        if ($e instanceof ProxmoxException || $e instanceof TaskDeadlineExceeded) {
            return $e->getMessage();
        }

        report($e);

        return 'Unexpected error ('.class_basename($e).'). See the application log.';
    }
}
