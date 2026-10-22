<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Domain\Settings\PanelSettings;
use App\Domain\Webhooks\Webhooks;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Enums\SuspensionCause;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageSample;
use App\Models\UsageViolation;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\TaskRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares what servers used with their traffic quota and the usage limits
 * of Panel settings (docs/adr/0039): opens a violation when one is exceeded,
 * resolves it when it no longer is, takes its action once, and unsuspends
 * servers the panel suspended over their traffic quota once they are back
 * within it.
 */
final readonly class UsageLimits
{
    /** The samples within a window must cover at least this share of it. */
    public const float COVERAGE = 0.8;

    public function __construct(
        private TaskRunner $tasks,
        private Webhooks $webhooks,
    ) {}

    /**
     * @param  Collection<int, Server>  $servers  servers sampled by this collection
     */
    public function check(Collection $servers): void
    {
        if ($servers->isEmpty()) {
            return;
        }

        /** @var array<int, Collection<int, UsageViolation>> $open */
        $open = UsageViolation::query()->open()->whereIn('server_id', $servers->modelKeys())->get()->groupBy('server_id')->all();

        $this->checkTraffic($servers, $open);
        $this->checkLimits($servers, $open);

        foreach ($servers as $server) {
            $violations = ($open[$server->id] ?? new Collection)->whereNull('resolved_at');

            foreach ($violations as $violation) {
                if ($violation->acted_at === null && $violation->action !== UsageAction::None) {
                    $this->act($server, $violation);
                }
            }

            if ($server->status === ServerStatus::Suspended
                && $server->suspension_cause === SuspensionCause::Traffic
                && $violations->where('kind', UsageKind::Traffic)->isEmpty()) {
                $this->unsuspend($server);
            }
        }
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  array<int, Collection<int, UsageViolation>>  $open
     */
    private function checkTraffic(Collection $servers, array &$open): void
    {
        $start = TrafficPeriods::start();
        $periods = TrafficPeriod::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->whereDate('period_start', $start->toDateString())
            ->get()
            ->keyBy('server_id');

        foreach ($servers as $server) {
            $violation = ($open[$server->id] ?? new Collection)->first(fn (UsageViolation $violation) => $violation->kind === UsageKind::Traffic);
            $used = $periods->get($server->id)?->counted() ?? 0;
            $over = $server->traffic_bytes !== null && $used >= $server->traffic_bytes;

            // A new period starts with a new violation; a raised or removed quota ends it.
            if ($violation !== null && (! $over || $violation->period_start?->toDateString() !== $start->toDateString())) {
                $this->resolve($violation);
                $violation = null;
            }

            if (! $over) {
                continue;
            }

            if ($violation === null) {
                $action = PanelSettings::get('traffic_action') === 'suspend' ? UsageAction::Suspend : UsageAction::None;
                $this->open($server, $open, UsageKind::Traffic, (float) $used, (float) $server->traffic_bytes, null, $action, $start);
            } else {
                $violation->forceFill(['value' => (float) $used])->save();
            }
        }
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  array<int, Collection<int, UsageViolation>>  $open
     */
    private function checkLimits(Collection $servers, array &$open): void
    {
        $limits = array_filter([
            UsageKind::Cpu->value => PanelSettings::get('usage_cpu_percent'),
            UsageKind::Memory->value => PanelSettings::get('usage_memory_percent'),
            UsageKind::Disk->value => PanelSettings::get('usage_disk_mb_s'),
            UsageKind::Network->value => PanelSettings::get('usage_network_mbps'),
        ], fn (mixed $limit) => is_numeric($limit));

        $window = max(UsageCollector::INTERVAL_MINUTES, (int) PanelSettings::get('usage_window_minutes'));
        $needed = (int) ceil($window / UsageCollector::INTERVAL_MINUTES * self::COVERAGE);
        $averages = $limits === [] ? collect() : $this->averages($servers, $window);
        $action = UsageAction::tryFrom((string) PanelSettings::get('usage_action')) ?? UsageAction::None;

        foreach ($servers as $server) {
            foreach (UsageKind::limits() as $kind) {
                $violation = ($open[$server->id] ?? new Collection)->first(fn (UsageViolation $violation) => $violation->kind === $kind && $violation->resolved_at === null);
                $limit = $limits[$kind->value] ?? null;

                if ($limit === null) {
                    // The limit was turned off.
                    if ($violation !== null) {
                        $this->resolve($violation);
                    }

                    continue;
                }

                /** @var array{float|null, int} $figure */
                $figure = $averages->get($server->id)[$kind->value] ?? [null, 0];
                [$average, $count] = $figure;

                // Too few samples (a new server, a collection that failed): leave it as it is.
                if ($average === null || $count < $needed) {
                    continue;
                }

                $over = $average >= (float) $limit;

                if ($over && $violation === null) {
                    $this->open($server, $open, $kind, $average, (float) $limit, $window, $action);
                } elseif ($over) {
                    $violation->forceFill(['value' => $average])->save();
                } elseif ($violation !== null) {
                    $this->resolve($violation);
                }
            }
        }
    }

    /**
     * Each server's averages over the window in the limits' units, with how many samples had the figure.
     *
     * @param  Collection<int, Server>  $servers
     * @return \Illuminate\Support\Collection<int, array{cpu: array{float|null, int}, memory: array{float|null, int}, disk: array{float|null, int}, network: array{float|null, int}}>
     */
    private function averages(Collection $servers, int $window): \Illuminate\Support\Collection
    {
        $rows = UsageSample::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->where('sampled_at', '>', now()->subMinutes($window))
            ->groupBy('server_id')
            ->select('server_id')
            ->selectRaw('avg(cpu_percent) as cpu, count(cpu_percent) as cpu_count')
            ->selectRaw('avg(memory_percent) as memory, count(memory_percent) as memory_count')
            ->selectRaw('avg(disk_read_rate + disk_write_rate) as disk, count(disk_read_rate + disk_write_rate) as disk_count')
            ->selectRaw('avg(net_in_rate + net_out_rate) as network, count(net_in_rate + net_out_rate) as network_count')
            ->toBase()
            ->get();

        $number = fn (mixed $value, float $factor = 1.0): ?float => is_numeric($value) ? round((float) $value * $factor, 1) : null;

        return $rows->mapWithKeys(fn (object $row) => [(int) $row->server_id => [
            UsageKind::Cpu->value => [$number($row->cpu), (int) $row->cpu_count],
            UsageKind::Memory->value => [$number($row->memory), (int) $row->memory_count],
            // Bytes per second to MB/s and Mbps.
            UsageKind::Disk->value => [$number($row->disk, 1 / 1_000_000), (int) $row->disk_count],
            UsageKind::Network->value => [$number($row->network, 8 / 1_000_000), (int) $row->network_count],
        ]]);
    }

    /**
     * @param  array<int, Collection<int, UsageViolation>>  $open
     */
    private function open(Server $server, array &$open, UsageKind $kind, float $value, float $threshold, ?int $window, UsageAction $action, ?CarbonImmutable $period = null): void
    {
        $violation = new UsageViolation;
        $violation->forceFill([
            'server_id' => $server->id,
            'kind' => $kind,
            'period_start' => $period?->toDateString(),
            'value' => $value,
            'threshold' => $threshold,
            'window_minutes' => $window,
            'action' => $action,
        ])->save();

        $violation->setRelation('server', $server);
        $open[$server->id] = ($open[$server->id] ?? new Collection)->push($violation);

        $this->webhooks->usageExceeded($violation);
    }

    private function resolve(UsageViolation $violation): void
    {
        $violation->forceFill(['resolved_at' => now()])->save();
    }

    /**
     * Take the violation's action once; a server that is busy or not active is tried again next time.
     */
    private function act(Server $server, UsageViolation $violation): void
    {
        if ($server->usage_exempt || $server->status !== ServerStatus::Active) {
            return;
        }

        if ($server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists()) {
            return;
        }

        $reason = $this->reason($violation);
        $suspend = $violation->action === UsageAction::Suspend;

        if ($suspend) {
            // Set before the task, so that the suspension carries it from the start.
            $server->forceFill([
                'suspension_cause' => $violation->kind === UsageKind::Traffic ? SuspensionCause::Traffic : SuspensionCause::Usage,
                'suspension_reason' => $reason,
            ])->save();
        }

        try {
            $task = DB::transaction(function () use ($server, $violation, $suspend) {
                $task = $this->tasks->start($server, $suspend ? TaskType::Suspend : PowerAction::Stop->taskType());
                $violation->forceFill(['acted_at' => now(), 'task_id' => $task->id])->save();

                return $task;
            });
        } catch (TaskAlreadyRunning) {
            if ($suspend) {
                $server->forceFill(['suspension_cause' => null, 'suspension_reason' => null])->save();
            }

            return;
        }

        AuditLog::record('server.usage_action', null, $server, [
            'hostname' => $server->hostname,
            'kind' => $violation->kind->value,
            'action' => $violation->action->value,
            'value' => $violation->value,
            'threshold' => $violation->threshold,
            'reason' => $reason,
            'task' => $task->uuid,
        ]);
    }

    private function unsuspend(Server $server): void
    {
        try {
            $this->tasks->start($server, TaskType::Unsuspend);
        } catch (TaskAlreadyRunning) {
            return;
        }

        AuditLog::record('server.usage_unsuspended', null, $server, ['hostname' => $server->hostname]);
    }

    /**
     * Why the server was suspended or stopped, as stored with it and shown to billing systems.
     */
    private function reason(UsageViolation $violation): string
    {
        if ($violation->kind === UsageKind::Traffic) {
            return sprintf('Traffic quota exceeded: %s GB of %s GB.', self::gigabytes($violation->value), self::gigabytes($violation->threshold));
        }

        $unit = match ($violation->kind->unit()) {
            'percent' => '%',
            default => ' '.$violation->kind->unit(),
        };

        return sprintf(
            'Usage limit exceeded: %s averaged %s%s over %d minutes (limit %s%s).',
            $violation->kind->label(),
            self::number($violation->value),
            $unit,
            (int) $violation->window_minutes,
            self::number($violation->threshold),
            $unit,
        );
    }

    private static function gigabytes(float $bytes): string
    {
        return self::number($bytes / 1000 ** 3);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
