<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

use Illuminate\Contracts\Config\Repository;

/**
 * BROADCAST_CONNECTION=reverb without Reverb's ID, key or secret would fail every
 * request and artisan command (the channels are registered on the broadcaster at
 * boot). Live figures are only a convenience, so the panel broadcasts to the log
 * instead and the server page polls (docs/adr/0036 §5).
 */
final class ReverbFallback
{
    /** Where the missing settings are kept, for servers:stats to warn about. */
    public const string CONFIG_KEY = 'broadcasting.missing_reverb_settings';

    private const array SETTINGS = [
        'app_id' => 'REVERB_APP_ID',
        'key' => 'REVERB_APP_KEY',
        'secret' => 'REVERB_APP_SECRET',
    ];

    /**
     * Switch to the log broadcaster when Reverb is chosen but not configured.
     */
    public static function apply(Repository $config): void
    {
        if ($config->get('broadcasting.default') !== 'reverb') {
            return;
        }

        $missing = [];

        foreach (self::SETTINGS as $key => $variable) {
            $value = $config->get("broadcasting.connections.reverb.{$key}");

            if (! is_string($value) || $value === '') {
                $missing[] = $variable;
            }
        }

        if ($missing !== []) {
            $config->set('broadcasting.default', 'log');
            $config->set(self::CONFIG_KEY, $missing);
        }
    }

    /**
     * The settings missing when the panel fell back to the log broadcaster.
     *
     * @return list<string>
     */
    public static function missing(Repository $config): array
    {
        $missing = $config->get(self::CONFIG_KEY, []);

        return is_array($missing) ? array_values(array_filter($missing, is_string(...))) : [];
    }
}
