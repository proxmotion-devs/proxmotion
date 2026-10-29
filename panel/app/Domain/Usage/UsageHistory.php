<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageRollup;
use App\Models\UsageSample;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A server's usage over a range (docs/adr/0050): the 5-minute samples of the
 * last day, hours of the last week or month, days of the last year, and its
 * traffic in buckets that suit the range. Missing points are left out.
 *
 * @phpstan-type Point array{time: string, cpu_percent: float|null, memory_percent: float|null, net_in_rate: float|null, net_out_rate: float|null, disk_read_rate: float|null, disk_write_rate: float|null, in_bytes: int, out_bytes: int}
 * @phpstan-type Bucket array{start: string, end: string, in_bytes: int, out_bytes: int, counted_bytes: int}
 */
final class UsageHistory
{
    public const string DEFAULT_RANGE = 'day';

    /** Each range's length in days, its points and its traffic buckets. */
    public const array RANGES = [
        'day' => ['days' => 1, 'points' => 'sample', 'traffic' => UsageRollup::HOUR],
        'week' => ['days' => 7, 'points' => UsageRollup::HOUR, 'traffic' => UsageRollup::DAY],
        'month' => ['days' => 30, 'points' => UsageRollup::HOUR, 'traffic' => UsageRollup::DAY],
        'year' => ['days' => 365, 'points' => UsageRollup::DAY, 'traffic' => 'period'],
    ];

    private const array SECONDS = ['sample' => UsageCollector::INTERVAL_MINUTES * 60, UsageRollup::HOUR => 3600, UsageRollup::DAY => 86400];

    /**
     * @return array{range: string, interval_seconds: int, from: string, to: string, points: list<Point>, traffic: array{interval: string, buckets: list<Bucket>}, current: array{period_start: string, period_end: string, in_bytes: int, out_bytes: int, counted_bytes: int, quota_bytes: int|null}}
     */
    public function for(Server $server, string $range): array
    {
        $spec = self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE];
        $to = UsageRollups::local(now());
        $from = $to->subDays($spec['days']);

        return [
            'range' => array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE,
            'interval_seconds' => self::SECONDS[$spec['points']],
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'points' => $spec['points'] === 'sample' ? $this->samples($server, $from) : $this->rollupPoints($server, $spec['points'], $from),
            'traffic' => [
                'interval' => $spec['traffic'],
                'buckets' => $spec['traffic'] === 'period' ? $this->periods($server, $to) : $this->rollupBuckets($server, $spec['traffic'], $from),
            ],
            'current' => TrafficPeriods::usage($server),
        ];
    }

    /**
     * @return list<Point>
     */
    private function samples(Server $server, CarbonImmutable $from): array
    {
        return array_values(UsageSample::query()
            ->where('server_id', $server->id)
            ->where('sampled_at', '>=', $from)
            ->orderBy('sampled_at')
            ->get()
            ->map(fn (UsageSample $sample) => [
                'time' => $sample->sampled_at->toIso8601String(),
                ...$this->averages($sample),
                'in_bytes' => $sample->net_in_bytes ?? 0,
                'out_bytes' => $sample->net_out_bytes ?? 0,
            ])
            ->all());
    }

    /**
     * @return list<Point>
     */
    private function rollupPoints(Server $server, string $resolution, CarbonImmutable $from): array
    {
        return array_values($this->rollups($server, $resolution, $from)
            ->map(fn (UsageRollup $rollup) => [
                'time' => UsageRollups::local($rollup->starts_at)->toIso8601String(),
                ...$this->averages($rollup),
                'in_bytes' => $rollup->in_bytes,
                'out_bytes' => $rollup->out_bytes,
            ])
            ->all());
    }

    /**
     * @return list<Bucket>
     */
    private function rollupBuckets(Server $server, string $resolution, CarbonImmutable $from): array
    {
        return array_values($this->rollups($server, $resolution, $from)
            ->map(function (UsageRollup $rollup) use ($resolution): array {
                $start = UsageRollups::local($rollup->starts_at);

                return [
                    'start' => $start->toIso8601String(),
                    'end' => ($resolution === UsageRollup::DAY ? $start->addDay() : $start->addHour())->toIso8601String(),
                    'in_bytes' => $rollup->in_bytes,
                    'out_bytes' => $rollup->out_bytes,
                    'counted_bytes' => TrafficPeriods::counted($rollup->in_bytes, $rollup->out_bytes),
                ];
            })
            ->all());
    }

    /**
     * The last 12 traffic periods, the current one included.
     *
     * @return list<Bucket>
     */
    private function periods(Server $server, CarbonImmutable $to): array
    {
        return array_values($server->trafficPeriods()
            ->whereDate('period_start', '>=', TrafficPeriods::start($to)->subMonthsNoOverflow(11)->toDateString())
            ->orderBy('period_start')
            ->get()
            ->map(function (TrafficPeriod $period): array {
                $start = UsageRollups::local($period->period_start)->startOfDay();

                return [
                    'start' => $start->toIso8601String(),
                    'end' => TrafficPeriods::end($start)->toIso8601String(),
                    'in_bytes' => $period->in_bytes,
                    'out_bytes' => $period->out_bytes,
                    'counted_bytes' => $period->counted(),
                ];
            })
            ->all());
    }

    /**
     * @return Collection<int, UsageRollup>
     */
    private function rollups(Server $server, string $resolution, CarbonImmutable $from): Collection
    {
        return UsageRollup::query()
            ->where('server_id', $server->id)
            ->where('resolution', $resolution)
            ->where('starts_at', '>=', $resolution === UsageRollup::DAY ? $from->startOfDay() : $from->startOfHour())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array{cpu_percent: float|null, memory_percent: float|null, net_in_rate: float|null, net_out_rate: float|null, disk_read_rate: float|null, disk_write_rate: float|null}
     */
    private function averages(UsageSample|UsageRollup $row): array
    {
        return [
            'cpu_percent' => $row->cpu_percent,
            'memory_percent' => $row->memory_percent,
            'net_in_rate' => $row->net_in_rate,
            'net_out_rate' => $row->net_out_rate,
            'disk_read_rate' => $row->disk_read_rate,
            'disk_write_rate' => $row->disk_write_rate,
        ];
    }
}
