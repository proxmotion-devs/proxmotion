<?php

declare(strict_types=1);

namespace App\Provisioning\Stages;

use App\Models\Task;
use App\Proxmox\Data\Upid;
use App\Proxmox\Exceptions\ProxmoxTaskFailed;
use App\Proxmox\ProxmoxClient;
use Closure;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Runs the stages of a staged task (docs/adr/0032) until one has to wait or
 * all are done. The stage a task is in is kept on the task, so the next run,
 * queued by RunServerTask after the wait, continues there.
 */
final readonly class StageRunner
{
    /** Seconds of work after which a run stops and queues the rest, far below a job's timeout. */
    public const RUN_BUDGET_SECONDS = 300;

    /**
     * @return int|null null once the last stage is done, else the seconds until the task should run again
     *
     * @throws TaskDeadlineExceeded when the task is past its deadline
     */
    public function advance(Task $task, StagedPipeline $pipeline): ?int
    {
        $stages = $pipeline->stages();
        $started = Carbon::now();
        $minutes = max(1, $pipeline instanceof HasOwnDeadline ? $pipeline->deadlineMinutes() : (int) config('proxmotion.provisioning.deadline_minutes'));
        $stage = $task->stage ?? $stages[0];
        $index = array_search($stage, $stages, true);

        if ($index === false) {
            throw new RuntimeException("Unknown stage {$stage}.");
        }

        $deadline = $task->deadline_at ?? $started->copy()->addMinutes($minutes);
        $task->forceFill(['stage' => $stage, 'deadline_at' => $deadline])->save();

        while (true) {
            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                throw TaskDeadlineExceeded::after($minutes);
            }

            $delay = $pipeline->runStage($task, $stage);
            $task->forceFill(['heartbeat_at' => Carbon::now()])->save();

            if ($delay !== null) {
                return max(0, $delay);
            }

            $next = $stages[++$index] ?? null;
            $task->forceFill(['stage' => $next, 'stage_state' => null])->save();

            if ($next === null) {
                return null;
            }

            $stage = $next;

            if ($started->diffInSeconds(Carbon::now()) >= self::RUN_BUDGET_SECONDS) {
                return 0;
            }
        }
    }

    /**
     * A Proxmox task of the current stage: started once, then checked once
     * per run until it has finished.
     *
     * @param  Closure(): ?Upid  $start  starts the Proxmox task; null when there is nothing to wait for
     * @return int|null null once it has finished (or nothing was started), else the seconds until the next check
     *
     * @throws ProxmoxTaskFailed when it fails or does not finish within $timeoutSeconds
     */
    public function proxmoxTask(Task $task, ProxmoxClient $client, Closure $start, int $timeoutSeconds, int $pollSeconds = 2): ?int
    {
        $value = $task->stageValue('upid');

        if (! is_string($value)) {
            $upid = $start();

            if ($upid === null) {
                return null;
            }

            $task->rememberForStage(['upid' => $upid->value, 'upid_deadline' => Carbon::now()->addSeconds($timeoutSeconds)->getTimestamp()]);
        } else {
            $upid = Upid::parse($value);
        }

        if ($client->taskWaiter()->check($upid)) {
            $task->rememberForStage(['upid' => null, 'upid_deadline' => null]);

            return null;
        }

        $deadline = $task->stageValue('upid_deadline');

        if (is_int($deadline) && Carbon::now()->getTimestamp() >= $deadline) {
            throw ProxmoxTaskFailed::timedOut($upid, $timeoutSeconds);
        }

        return $pollSeconds;
    }
}
