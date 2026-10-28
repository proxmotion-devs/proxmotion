<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * The reports of the reports section (docs/adr/0046), by the key in their
 * address. The page titles are in resources/js/lib/reports.ts.
 */
final class Reports
{
    /** @var array<string, class-string<Report>> */
    public const array PAGES = [
        'capacity' => CapacityReport::class,
        'traffic' => TrafficReport::class,
        'servers' => ServerInventoryReport::class,
        'operations' => OperationsReport::class,
    ];

    public static function make(string $key): Report
    {
        return app(self::PAGES[$key]);
    }

    /**
     * Share of $part in $whole in per cent with one decimal, null when the
     * whole is unknown or zero.
     */
    public static function percent(int|float|null $part, int|float|null $whole): ?float
    {
        return $part === null || $whole === null || $whole <= 0 ? null : round($part / $whole * 100, 1);
    }

    /**
     * Sum of a column, null when no row knows it.
     *
     * @param  list<array<string, string|int|float|bool|null>>  $rows
     */
    public static function sum(array $rows, string $key): int|float|null
    {
        $values = array_filter(array_column($rows, $key), fn ($value) => is_int($value) || is_float($value));

        return $values === [] ? null : array_sum($values);
    }

    /**
     * Rows grouped by a label, each with the number of rows and the sums of
     * the given columns, largest group first.
     *
     * @param  list<array<string, string|int|float|bool|null>>  $rows
     * @param  callable(array<string, string|int|float|bool|null>): string  $label
     * @param  list<string>  $sums
     * @return list<array<string, string|int|float|bool|null>>
     */
    public static function group(array $rows, string $labelKey, callable $label, string $countKey, array $sums): array
    {
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, array<string, int|float>> $totals */
        $totals = [];

        foreach ($rows as $row) {
            $name = $label($row);
            $counts[$name] = ($counts[$name] ?? 0) + 1;

            foreach ($sums as $key) {
                $value = $row[$key] ?? 0;
                $totals[$name][$key] = ($totals[$name][$key] ?? 0) + (is_int($value) || is_float($value) ? $value : 0);
            }
        }

        $names = array_keys($counts);
        usort($names, fn (string|int $a, string|int $b) => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return array_map(fn (string|int $name) => [
            $labelKey => (string) $name,
            $countKey => $counts[$name],
            ...array_merge(array_fill_keys($sums, 0), $totals[$name] ?? []),
        ], $names);
    }

    /**
     * @param  list<int|float>  $values
     */
    public static function median(array $values): int|float|null
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
