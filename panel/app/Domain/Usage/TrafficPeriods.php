<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Domain\Settings\PanelSettings;
use App\Models\Server;
use App\Models\TrafficPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Monthly traffic periods (docs/adr/0039): a period starts on the traffic
 * reset day of Panel settings at midnight in the panel's time zone, and
 * what counts against a quota is incoming and outgoing traffic together,
 * or one of them.
 */
final class TrafficPeriods
{
    /**
     * The start of the period $at falls in.
     */
    public static function start(?CarbonInterface $at = null): CarbonImmutable
    {
        $at = CarbonImmutable::instance($at ?? now())->setTimezone((string) config('app.timezone'));
        $day = max(1, min(28, (int) PanelSettings::get('traffic_reset_day')));
        $start = $at->startOfMonth()->setDay($day);

        return $start->greaterThan($at) ? $start->subMonthNoOverflow() : $start;
    }

    /**
     * The first moment after the period that starts at $start.
     */
    public static function end(CarbonInterface $start): CarbonImmutable
    {
        return CarbonImmutable::instance($start)->addMonthNoOverflow();
    }

    /**
     * The server's traffic in the current period (its trafficPeriods are used when loaded).
     *
     * @return array{period_start: string, period_end: string, in_bytes: int, out_bytes: int, counted_bytes: int, quota_bytes: int|null}
     */
    public static function usage(Server $server): array
    {
        $start = self::start();
        $day = $start->toDateString();
        $period = $server->relationLoaded('trafficPeriods')
            ? $server->trafficPeriods->first(fn (TrafficPeriod $period) => $period->period_start->toDateString() === $day)
            : $server->trafficPeriods()->whereDate('period_start', $day)->first();
        $in = $period->in_bytes ?? 0;
        $out = $period->out_bytes ?? 0;

        return [
            'period_start' => $start->toIso8601String(),
            'period_end' => self::end($start)->toIso8601String(),
            'in_bytes' => $in,
            'out_bytes' => $out,
            'counted_bytes' => self::counted($in, $out),
            'quota_bytes' => $server->traffic_bytes,
        ];
    }

    /**
     * The bytes that count against a quota.
     */
    public static function counted(int $in, int $out): int
    {
        return match (PanelSettings::get('traffic_counted')) {
            'in' => $in,
            'out' => $out,
            default => $in + $out,
        };
    }
}
