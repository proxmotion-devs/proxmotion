<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

/**
 * Sends one HTTP request. CurlTransport in WISECP, a fake in tests.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string} status 0 when the panel could not be reached
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
