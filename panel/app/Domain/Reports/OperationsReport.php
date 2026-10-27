<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Enums\AppInstallationStatus;
use App\Enums\TaskStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tasks and app installations started in the last days: how many of each
 * kind succeeded and failed, how long successful tasks took, and the
 * failed tasks with their errors.
 */
final class OperationsReport implements Report
{
    private const array DAYS = [7, 30, 90, 365];

    private const int DEFAULT_DAYS = 30;

    public function rules(): array
    {
        return ['days' => ['nullable', 'integer', Rule::in(self::DAYS)]];
    }

    public function filters(array $input): array
    {
        return ['days' => isset($input['days']) ? (int) $input['days'] : self::DEFAULT_DAYS];
    }

    public function filterOptions(): array
    {
        return [
            'days' => [
                'label' => __('Period'),
                'all' => null,
                'options' => array_map(fn (int $days) => ['value' => $days, 'label' => __('Last :days days', ['days' => (string) $days])], self::DAYS),
            ],
        ];
    }

    public function tables(array $filters): array
    {
        $since = now()->subDays((int) $filters['days']);

        return [$this->taskTypes($since), $this->failedTasks($since), $this->apps($since)];
    }

    private function taskTypes(CarbonInterface $since): ReportTable
    {
        $counts = Task::query()->where('created_at', '>=', $since)
            ->groupBy('type', 'status')
            ->selectRaw('type, status, count(*) as tasks')
            ->toBase()
            ->get();

        /** @var array<string, list<int>> $durations */
        $durations = [];

        foreach (Task::query()->where('created_at', '>=', $since)->where('status', TaskStatus::Succeeded)
            ->whereNotNull('started_at')->whereNotNull('finished_at')
            ->get(['id', 'type', 'started_at', 'finished_at']) as $task) {
            $durations[$task->type->value][] = max(0, (int) $task->started_at?->diffInSeconds($task->finished_at, true));
        }

        $rows = [];

        foreach ($counts as $count) {
            $type = (string) $count->type;
            $rows[$type] ??= ['type' => $type, 'tasks' => 0, 'succeeded' => 0, 'failed' => 0, 'open' => 0];
            $rows[$type]['tasks'] += (int) $count->tasks;
            $column = match (TaskStatus::from((string) $count->status)) {
                TaskStatus::Succeeded => 'succeeded',
                TaskStatus::Failed => 'failed',
                TaskStatus::Queued, TaskStatus::Running => 'open',
            };
            $rows[$type][$column] += (int) $count->tasks;
        }

        $rows = array_map(fn (array $row) => [
            ...$row,
            'failure_percent' => Reports::percent($row['failed'], $row['succeeded'] + $row['failed']),
            'median_seconds' => Reports::median($durations[$row['type']] ?? []),
        ], array_values($rows));
        usort($rows, fn (array $a, array $b) => [$b['tasks'], $a['type']] <=> [$a['tasks'], $b['type']]);

        $succeeded = Reports::sum($rows, 'succeeded');
        $failed = Reports::sum($rows, 'failed');

        return new ReportTable(
            'task_types',
            __('Tasks by type'),
            __('Failure rate counts finished tasks only. Duration is the median of successful tasks, from start to finish.'),
            [
                new ReportColumn('type', __('Task'), 'task_type'),
                new ReportColumn('tasks', __('Tasks'), 'number'),
                new ReportColumn('succeeded', __('Succeeded'), 'number'),
                new ReportColumn('failed', __('Failed'), 'number'),
                new ReportColumn('open', __('Queued or running'), 'number'),
                new ReportColumn('failure_percent', __('Failure rate'), 'percent'),
                new ReportColumn('median_seconds', __('Median duration'), 'duration'),
            ],
            $rows,
            $rows === [] ? null : [
                'type' => __('Total'),
                'tasks' => Reports::sum($rows, 'tasks'),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'open' => Reports::sum($rows, 'open'),
                'failure_percent' => Reports::percent($failed, ($succeeded ?? 0) + ($failed ?? 0)),
            ],
        );
    }

    private function failedTasks(CarbonInterface $since): ReportTable
    {
        $rows = array_values(Task::query()->where('created_at', '>=', $since)->where('status', TaskStatus::Failed)
            ->with(['server:id,uuid,hostname', 'user:id,name', 'apiToken:id,billing_system_id', 'apiToken.billingSystem:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (Task $task) => [
                'finished_at' => ($task->finished_at ?? $task->updated_at)?->toIso8601String(),
                'server' => $task->server->hostname,
                'server_uuid' => $task->server->uuid,
                'type' => $task->type->value,
                'started_by' => match (true) {
                    $task->user !== null => $task->user->name,
                    $task->apiToken !== null => __(':name (API)', ['name' => $task->apiToken->billingSystem->name]),
                    default => __('Panel'),
                },
                'error' => $task->error === null ? null : Str::limit($task->error, 500),
            ])
            ->all());

        return new ReportTable(
            'failed_tasks',
            __('Failed tasks'),
            __('Newest first. Started by the panel means a scheduled check or an automatic action.'),
            [
                new ReportColumn('finished_at', __('Failed at'), 'datetime'),
                new ReportColumn('server', __('Server'), 'server'),
                new ReportColumn('type', __('Task'), 'task_type'),
                new ReportColumn('started_by', __('Started by')),
                new ReportColumn('error', __('Error')),
            ],
            $rows,
        );
    }

    private function apps(CarbonInterface $since): ReportTable
    {
        $counts = AppInstallation::query()->where('created_at', '>=', $since)->where('is_trial', false)
            ->groupBy('app_recipe_id', 'status')
            ->selectRaw('app_recipe_id, status, count(*) as installations')
            ->toBase()
            ->get();
        $names = AppRecipe::query()->whereIn('id', $counts->pluck('app_recipe_id')->unique())->pluck('name', 'id');

        $rows = [];

        foreach ($counts as $count) {
            $id = (int) $count->app_recipe_id;
            $rows[$id] ??= ['app' => (string) ($names[$id] ?? $id), 'installations' => 0, 'installed' => 0, 'failed' => 0, 'other' => 0];
            $rows[$id]['installations'] += (int) $count->installations;
            $column = match (AppInstallationStatus::from((string) $count->status)) {
                AppInstallationStatus::Installed => 'installed',
                AppInstallationStatus::Failed => 'failed',
                default => 'other',
            };
            $rows[$id][$column] += (int) $count->installations;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$b['installations'], $a['app']] <=> [$a['installations'], $b['app']]);

        return new ReportTable(
            'apps',
            __('App installations'),
            __('Installations started in the period by their status now. Other: still running, being uninstalled or upgraded, or removed. Trials are left out.'),
            [
                new ReportColumn('app', __('App')),
                new ReportColumn('installations', __('Installations'), 'number'),
                new ReportColumn('installed', __('Installed'), 'number'),
                new ReportColumn('failed', __('Failed'), 'number'),
                new ReportColumn('other', __('Other'), 'number'),
            ],
            $rows,
            $rows === [] ? null : [
                'app' => __('Total'),
                'installations' => Reports::sum($rows, 'installations'),
                'installed' => Reports::sum($rows, 'installed'),
                'failed' => Reports::sum($rows, 'failed'),
                'other' => Reports::sum($rows, 'other'),
            ],
        );
    }
}
