<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A small unified diff of two texts, line by line, for audit log entries
 * (docs/adr/0018 §6). Inputs are recipe files of at most a few thousand
 * lines; the longest common subsequence table is fine at that size.
 */
final class TextDiff
{
    /** Lines beyond which the texts are summarised instead of compared. */
    public const MAX_LINES = 4000;

    /**
     * The unified diff of $old and $new with 3 lines of context, or an empty
     * string when they are equal.
     */
    public static function unified(string $old, string $new, string $path, int $context = 3): string
    {
        if ($old === $new) {
            return '';
        }

        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $header = "--- a/{$path}\n+++ b/{$path}\n";

        if (count($a) > self::MAX_LINES || count($b) > self::MAX_LINES) {
            return $header.'@@ '.count($a).' lines → '.count($b)." lines (too long to compare) @@\n";
        }

        $ops = self::operations($a, $b);
        $hunks = [];
        $count = count($ops);
        $i = 0;

        while ($i < $count) {
            if ($ops[$i][0] === ' ') {
                $i++;

                continue;
            }

            // A hunk: from $context lines before this change to $context lines after the last change close to it.
            $start = max(0, $i - $context);
            $end = $i;

            while ($end < $count) {
                if ($ops[$end][0] !== ' ') {
                    $end++;

                    continue;
                }

                $next = $end;

                while ($next < $count && $ops[$next][0] === ' ' && $next - $end < 2 * $context) {
                    $next++;
                }

                if ($next < $count && $ops[$next][0] !== ' ') {
                    $end = $next;

                    continue;
                }

                break;
            }

            $stop = min($count, $end + $context);
            [$oldLine, $newLine] = self::position($ops, $start);
            $oldLength = count(array_filter(array_slice($ops, $start, $stop - $start), fn (array $op) => $op[0] !== '+'));
            $newLength = count(array_filter(array_slice($ops, $start, $stop - $start), fn (array $op) => $op[0] !== '-'));
            $lines = array_map(fn (array $op) => $op[0].$op[1], array_slice($ops, $start, $stop - $start));
            $hunks[] = "@@ -{$oldLine},{$oldLength} +{$newLine},{$newLength} @@\n".implode("\n", $lines)."\n";
            $i = $stop;
        }

        return $header.implode('', $hunks);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{string, string}> ' ', '-' or '+' with the line
     */
    private static function operations(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $ops[] = ['-', $a[$i]];
                $i++;
            } else {
                $ops[] = ['+', $b[$j]];
                $j++;
            }
        }

        for (; $i < $n; $i++) {
            $ops[] = ['-', $a[$i]];
        }

        for (; $j < $m; $j++) {
            $ops[] = ['+', $b[$j]];
        }

        return $ops;
    }

    /**
     * The 1-based old and new line numbers where operation $index starts.
     *
     * @param  list<array{string, string}>  $ops
     * @return array{int, int}
     */
    private static function position(array $ops, int $index): array
    {
        $old = 1;
        $new = 1;

        foreach (array_slice($ops, 0, $index) as $op) {
            $old += $op[0] === '+' ? 0 : 1;
            $new += $op[0] === '-' ? 0 : 1;
        }

        return [$old, $new];
    }
}
