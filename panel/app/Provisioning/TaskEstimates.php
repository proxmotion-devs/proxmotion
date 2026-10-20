<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * How long a create or reinstall still takes (docs/adr/0032 §5): for each
 * step not done yet, the median time that step took in the latest successful
 * tasks of the same type with the same OS template, less the time the
 * current step has already run. Only an estimate: the queue, the node and the
 * guest decide the real time.
 */
final readonly class TaskEstimates
{
    /** How many earlier tasks are looked at. */
    public const HISTORY = 20;

    /** Fewer earlier tasks than this give no estimate. */
    public const MIN_HISTORY = 3;

    /**
     * @param  list<string>  $planned  the steps the task is expected to record (TaskPlan)
     * @return array{remaining_seconds: int, based_on: int}|null null when there is nothing to estimate or too little history
     */
    public function remaining(Task $task, array $planned): ?array
    {
        if (! in_array($task->status, [TaskStatus::Queued, TaskStatus::Running], true)
            || ! in_array($task->type, [TaskType::Create, TaskType::Reinstall], true)
            || $task->os_template_id === null) {
            return null;
        }

        [$medians, $basedOn] = $this->medians($task);

        if ($basedOn < self::MIN_HISTORY) {
            return null;
        }

        $done = [];
        $runningSince = null;
        $lastFinished = null;

        foreach ($task->steps as $step) {
            if ($step['status'] === 'done') {
                $done[$step['key']] = true;
                $finished = self::time($step['finished_at']);
                $lastFinished = $finished !== null && ($lastFinished === null || $finished->gt($lastFinished)) ? $finished : $lastFinished;
            } elseif ($step['status'] === 'running') {
                $runningSince = self::time($step['started_at'] ?? null);
            }
        }

        $now = Carbon::now();
        $activeSince = $task->status === TaskStatus::Running ? ($runningSince ?? $lastFinished ?? $task->started_at ?? $now) : null;
        $remaining = 0.0;

        foreach (array_unique([...$planned, ...array_column($task->steps, 'key')]) as $key) {
            if (isset($done[$key])) {
                continue;
            }

            $expected = $medians[$key] ?? 0.0;

            // The first step not done yet is the one running now.
            if ($activeSince !== null) {
                $expected = max(0.0, $expected - $activeSince->diffInSeconds($now));
                $activeSince = null;
            }

            $remaining += $expected;
        }

        return ['remaining_seconds' => (int) round($remaining), 'based_on' => $basedOn];
    }

    /**
     * @return array{0: array<string, float>, 1: int} median seconds per step key, and how many tasks had timings
     */
    private function medians(Task $task): array
    {
        $history = Task::query()
            ->where('type', $task->type)
            ->where('status', TaskStatus::Succeeded)
            ->where('os_template_id', $task->os_template_id)
            ->whereKeyNot($task->id)
            ->latest('id')
            ->limit(self::HISTORY)
            ->get(['id', 'steps']);

        $durations = [];
        $basedOn = 0;

        foreach ($history as $earlier) {
            $timed = false;

            foreach ($earlier->steps as $step) {
                $started = self::time($step['started_at'] ?? null);
                $finished = self::time($step['finished_at']);

                if ($step['status'] === 'done' && $started !== null && $finished !== null) {
                    $durations[$step['key']][] = max(0.0, $started->diffInSeconds($finished));
                    $timed = true;
                }
            }

            $basedOn += $timed ? 1 : 0;
        }

        return [array_map(self::median(...), $durations), $basedOn];
    }

    /**
     * @param  non-empty-list<float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function time(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
