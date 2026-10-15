<?php

declare(strict_types=1);

namespace App\Support;

final class Locales
{
    /**
     * Locales the UI is translated into. Keep in sync with `lang/*.json`.
     */
    public const array SUPPORTED = ['en', 'tr'];

    /**
     * @phpstan-assert-if-true string $locale
     */
    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }
}
