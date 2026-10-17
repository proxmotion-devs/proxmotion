<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

/**
 * The Proxmotion-Signature header of a webhook: "t=<unix timestamp>,v1=<hex>",
 * where v1 is the HMAC-SHA256 of "<t>.<raw body>" with the endpoint's signing
 * secret (whsec_…). The timestamp lets a captured request be refused later.
 */
final class WebhookSignature
{
    /** Requests signed longer ago (or further ahead) than this are refused. */
    public const TOLERANCE_SECONDS = 300;

    private ?int $timestamp = null;

    /** @var list<string> */
    private array $signatures = [];

    public function __construct(string $header)
    {
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't' && ctype_digit($pair[1])) {
                $this->timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1' && $pair[1] !== '') {
                $this->signatures[] = strtolower($pair[1]);
            }
        }
    }

    /**
     * The header has a timestamp and at least one v1 signature.
     */
    public function isWellFormed(): bool
    {
        return $this->timestamp !== null && $this->signatures !== [];
    }

    /**
     * The timestamp is within TOLERANCE_SECONDS of now.
     */
    public function isFresh(int $now): bool
    {
        return $this->timestamp !== null && abs($now - $this->timestamp) <= self::TOLERANCE_SECONDS;
    }

    /**
     * The body was signed with this secret (compared in constant time).
     */
    public function matches(string $body, string $secret): bool
    {
        if ($this->timestamp === null || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $this->timestamp . '.' . $body, $secret);
        $matched = false;

        foreach ($this->signatures as $signature) {
            // No early return: every signature is compared.
            $matched = hash_equals($expected, $signature) || $matched;
        }

        return $matched;
    }

    /**
     * A header for this body, as Proxmotion builds it (for tests).
     */
    public static function header(string $secret, string $body, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
