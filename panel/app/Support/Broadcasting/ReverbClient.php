<?php

declare(strict_types=1);

namespace App\Support\Broadcasting;

/**
 * Where browsers reach Reverb (docs/adr/0036, 0054), shared with the pages of
 * signed-in administrators. Read when a page loads rather than compiled into
 * the frontend, so one built image fits every installation.
 */
final class ReverbClient
{
    /**
     * Null while the panel does not broadcast through Reverb (also after
     * ReverbFallback switched to the log broadcaster): pages poll instead.
     *
     * @return array{key: string, host: string|null, port: int|null, scheme: 'http'|'https'|null}|null
     */
    public static function forPages(): ?array
    {
        $key = config('broadcasting.connections.reverb.key');

        if (config('broadcasting.default') !== 'reverb' || ! is_string($key) || $key === '') {
            return null;
        }

        $host = config('proxmotion.reverb_public.host');
        $port = config('proxmotion.reverb_public.port');
        $scheme = config('proxmotion.reverb_public.scheme');

        return [
            'key' => $key,
            'host' => is_string($host) && $host !== '' ? $host : null,
            'port' => is_numeric($port) && (int) $port > 0 && (int) $port <= 65535 ? (int) $port : null,
            'scheme' => in_array($scheme, ['http', 'https'], true) ? $scheme : null,
        ];
    }
}
