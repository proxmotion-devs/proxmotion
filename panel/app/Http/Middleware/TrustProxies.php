<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

/**
 * Forwarded headers count only from the TLS proxy named in TRUSTED_PROXIES
 * (docs/adr/0054); without it, none do. Read per request; the parent splits
 * the comma-separated list and knows `*`.
 */
class TrustProxies extends Middleware
{
    protected function proxies(): ?string
    {
        $proxies = config('proxmotion.trusted_proxies');

        return is_string($proxies) && trim($proxies) !== '' ? trim($proxies) : null;
    }
}
