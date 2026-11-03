<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Models\UsageRollup;
use App\Models\UsageSample;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rolls samples up into hours and hours into days (docs/adr/0050). Each
 * collection recomputes the current and the previous hour of the servers it
 * sampled, and the days they fall in; rows are upserted, so a repeated run
 * changes nothing.
 */
final class UsageRollups
{
    /** The figures averaged over samples (or weighted by samples, for days). */
    public const array AVERAGED = ['cpu_percent', 'memory_percent', 'net_in_rate', 'net_out_rate', 'disk_read_rate', 'disk_write_rate'];

    private const array UNIQUE = ['server_id', 'resolution', 'starts_at'];

    /**
     * @param  array<int, int>  $serverIds
     */
    public function roll(array $serverIds, ?CarbonInterface $at = null): void
    {
        $at = self::local($at ?? now());
        $from = $at->startOfHour()->subHour();
        $until = $at->startOfHour()->addHour();
        $updated = [...self::AVERAGED, 'samples', 'in_bytes', 'out_bytes'];

        foreach (array_chunk($serverIds, 100) as $chunk) {
            $hours = UsageSample::query()
                ->whereIn('server_id', $chunk)
                ->where('sampled_at', '>=', $from)
                ->where('sampled_at', '<', $until)
                ->get()
                ->groupBy(fn (UsageSample $sample) => $sample->server_id.'|'.self::local($sample->sampled_at)->startOfHour()->toDateTimeString())
                ->map(fn ($samples, string $key) => $this->row($key, UsageRollup::HOUR, $samples->map(fn (UsageSample $sample) => [
                    ...$sample->only(self::AVERAGED),
                    'weight' => 1,
                    'in_bytes' => $sample->net_in_bytes ?? 0,
                    'out_bytes' => $sample->net_out_bytes ?? 0,
                ])->all()))
                ->values()
                ->all();

            if ($hours === []) {
                continue;
            }

            UsageRollup::query()->upsert($hours, self::UNIQUE, $updated);

            foreach (array_unique([$from->startOfDay()->toDateTimeString(), $at->startOfDay()->toDateTimeString()]) as $day) {
                $start = CarbonImmutable::parse($day, $at->getTimezone());

                $days = UsageRollup::query()
                    ->whereIn('server_id', $chunk)
                    ->where('resolution', UsageRollup::HOUR)
                    ->where('starts_at', '>=', $start)
                    ->where('starts_at', '<', $start->addDay())
                    ->get()
                    ->groupBy(fn (UsageRollup $hour) => $hour->server_id.'|'.$day)
                    ->map(fn ($rows, string $key) => $this->row($key, UsageRollup::DAY, $rows->map(fn (UsageRollup $hour) => [
                        ...$hour->only(self::AVERAGED),
                        'weight' => $hour->samples,
                        'in_bytes' => $hour->in_bytes,
                        'out_bytes' => $hour->out_bytes,
                    ])->all()))
                    ->values()
                    ->all();

                if ($days !== []) {
                    UsageRollup::query()->upsert($days, self::UNIQUE, $updated);
                }
            }
        }
    }

    /**
     * The time in the panel's time zone, where hours and days start.
     */
    public static function local(CarbonInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone((string) config('app.timezone'));
    }

    /**
     * One rollup row from weighted items: averages over the items that have a value, bytes summed.
     *
     * @param  array<int, array<string, float|int|null>>  $items
     * @return array<string, mixed>
     */
    private function row(string $key, string $resolution, array $items): array
    {
        [$serverId, $start] = explode('|', $key, 2);
        $row = [
            'server_id' => (int) $serverId,
            'resolution' => $resolution,
            'starts_at' => $start,
            'samples' => (int) array_sum(array_column($items, 'weight')),
            'in_bytes' => (int) array_sum(array_column($items, 'in_bytes')),
            'out_bytes' => (int) array_sum(array_column($items, 'out_bytes')),
        ];

        foreach (self::AVERAGED as $figure) {
            $sum = 0.0;
            $weight = 0;

            foreach ($items as $item) {
                if ($item[$figure] !== null && $item['weight'] > 0) {
                    $sum += (float) $item[$figure] * (int) $item['weight'];
                    $weight += (int) $item['weight'];
                }
            }

            $row[$figure] = $weight === 0 ? null : round($sum / $weight, 1);
        }

        return $row;
    }
}
