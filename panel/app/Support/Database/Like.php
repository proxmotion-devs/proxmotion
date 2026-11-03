<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * LIKE patterns from text a user typed, with `%` and `_` matching only
 * themselves. MySQL escapes with a backslash by default and SQLite not at
 * all, so the escape character is `!` and the query names it:
 *
 *     $query->whereRaw("notes like ? escape '!'", [Like::contains($search)])
 */
final class Like
{
    public const ESCAPE = '!';

    /**
     * A pattern matching values that contain the text.
     */
    public static function contains(string $text): string
    {
        return '%'.self::escape($text).'%';
    }

    public static function escape(string $text): string
    {
        return strtr($text, [
            self::ESCAPE => self::ESCAPE.self::ESCAPE,
            '%' => self::ESCAPE.'%',
            '_' => self::ESCAPE.'_',
        ]);
    }
}
