<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use SensitiveParameter;

/**
 * The Proxmotion-Signature header (docs/adr/0013): an HMAC-SHA256 of
 * "<timestamp>.<body>" with the endpoint's secret, in the form
 * "t=<unix timestamp>,v1=<hex>". The timestamp lets receivers refuse old
 * requests that were captured and replayed.
 */
final class WebhookSignature
{
    public const HEADER = 'Proxmotion-Signature';

    public static function header(#[SensitiveParameter] string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
