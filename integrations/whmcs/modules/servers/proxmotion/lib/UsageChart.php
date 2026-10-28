<?php

namespace Proxmotion\Whmcs;

/**
 * Usage charts of the client area (docs/adr/0050) as inline SVG: lines of
 * averages and stacked bars of traffic, with the values in each point's
 * title (shown on hover). No JavaScript and no external library.
 */
class UsageChart
{
    const WIDTH = 600;
    const HEIGHT = 140;
    /** The panel's two series colours. */
    const COLORS = ['#2a78d6', '#eb6834'];

    /**
     * Lines of one or two figures; a gap longer than one and a half points breaks a line.
     *
     * @param list<array<string, mixed>> $points with "time" (ISO 8601) and the series' keys
     * @param array<string, string> $series key => label
     * @param callable(float): string $format
     * @param callable(string): string $time
     */
    public static function line(array $points, array $series, string $from, string $to, int $intervalSeconds, ?float $max, callable $format, callable $time): string
    {
        $start = (int) strtotime($from);
        $span = max(1, (int) strtotime($to) - $start);
        $top = $max !== null ? $max : self::top($points, array_keys($series), false);
        $gap = $intervalSeconds * 1.5;
        $paths = '';
        $index = 0;

        foreach ($series as $key => $label) {
            $d = '';
            $previous = null;

            foreach ($points as $point) {
                $value = isset($point[$key]) && is_numeric($point[$key]) ? (float) $point[$key] : null;
                $at = (int) strtotime((string) $point['time']);

                if ($value === null) {
                    $previous = null;
                    continue;
                }

                $d .= ($previous !== null && $at - $previous <= $gap ? 'L' : 'M') . self::x($at, $start, $span) . ',' . self::y($value, $top);
                $previous = $at;
            }

            $paths .= '<path d="' . $d . '" fill="none" stroke="' . self::COLORS[$index % 2] . '" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>';
            $index++;
        }

        // An invisible column per point carries its values as a tooltip.
        $columns = '';
        $width = max(1.0, $intervalSeconds / $span * self::WIDTH);

        foreach ($points as $point) {
            $at = (int) strtotime((string) $point['time']);
            $columns .= '<rect x="' . round(self::x($at, $start, $span) - $width / 2, 1) . '" y="0" width="' . round($width, 1) . '" height="' . self::HEIGHT . '" fill="transparent"><title>' . self::title($point, $series, $format, $time) . '</title></rect>';
        }

        return self::frame($paths . $columns, $format($top), $time($from), $time($to));
    }

    /**
     * Stacked bars of traffic buckets.
     *
     * @param list<array<string, mixed>> $buckets with "start" and "end" (ISO 8601) and the series' keys
     * @param array<string, string> $series key => label
     * @param callable(float): string $format
     * @param callable(string): string $time
     */
    public static function bars(array $buckets, array $series, string $from, string $to, callable $format, callable $time): string
    {
        $start = (int) strtotime($from);
        $span = max(1, (int) strtotime($to) - $start);
        $top = self::top($buckets, array_keys($series), true);
        $bars = '';

        foreach ($buckets as $bucket) {
            $left = self::x((int) strtotime((string) $bucket['start']), $start, $span);
            $width = max(1.0, self::x((int) strtotime((string) $bucket['end']), $start, $span) - $left - 1);
            $base = 0.0;
            $index = 0;
            $bars .= '<g><title>' . self::title(array_merge($bucket, ['time' => $bucket['start']]), $series, $format, $time) . '</title>';

            foreach (array_keys($series) as $key) {
                $value = isset($bucket[$key]) && is_numeric($bucket[$key]) ? (float) $bucket[$key] : 0.0;
                $bottom = self::y($base, $top);
                $base += $value;
                $bars .= '<rect x="' . $left . '" y="' . self::y($base, $top) . '" width="' . round($width, 1) . '" height="' . round(max(0.0, $bottom - self::y($base, $top)), 1) . '" fill="' . self::COLORS[$index % 2] . '"/>';
                $index++;
            }

            $bars .= '</g>';
        }

        return self::frame($bars, $format($top), $time($from), $time($to));
    }

    /**
     * The largest value (or stack of values), at least 1.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keys
     */
    private static function top(array $rows, array $keys, bool $stacked): float
    {
        $top = 1.0;

        foreach ($rows as $row) {
            $values = [];

            foreach ($keys as $key) {
                $values[] = isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : 0.0;
            }

            $top = max($top, $stacked ? array_sum($values) : max($values));
        }

        return $top;
    }

    private static function x(int $at, int $start, int $span): float
    {
        return round(min(max(($at - $start) / $span, 0), 1) * self::WIDTH, 1);
    }

    private static function y(float $value, float $top): float
    {
        return round(self::HEIGHT - 1 - min($value, $top) / $top * (self::HEIGHT - 2), 1);
    }

    /**
     * "time — label value, label value", escaped for the SVG.
     *
     * @param array<string, mixed> $row
     * @param array<string, string> $series
     */
    private static function title(array $row, array $series, callable $format, callable $time): string
    {
        $values = [];

        foreach ($series as $key => $label) {
            $values[] = (count($series) > 1 ? $label . ' ' : '') . (isset($row[$key]) && is_numeric($row[$key]) ? $format((float) $row[$key]) : '—');
        }

        return htmlspecialchars($time((string) $row['time']) . ' — ' . implode(', ', $values), ENT_QUOTES, 'UTF-8');
    }

    private static function frame(string $content, string $top, string $from, string $to): string
    {
        $grid = '';

        foreach ([0.5, self::HEIGHT / 2 + 0.5] as $y) {
            $grid .= '<line x1="0" x2="' . self::WIDTH . '" y1="' . $y . '" y2="' . $y . '" stroke="#ddd" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"/>';
        }

        $grid .= '<line x1="0" x2="' . self::WIDTH . '" y1="' . (self::HEIGHT - 0.5) . '" y2="' . (self::HEIGHT - 0.5) . '" stroke="#ccc" vector-effect="non-scaling-stroke"/>';
        $escape = function ($text) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

        return '<div style="position: relative;">'
            . '<small class="text-muted" style="position: absolute; top: 0; left: 4px;">' . $escape($top) . '</small>'
            . '<svg viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" preserveAspectRatio="none" style="display: block; width: 100%; height: ' . self::HEIGHT . 'px;" role="img">' . $grid . $content . '</svg>'
            . '<div class="text-muted small" style="display: flex; justify-content: space-between;"><span>' . $escape($from) . '</span><span>' . $escape($to) . '</span></div>'
            . '</div>';
    }
}
