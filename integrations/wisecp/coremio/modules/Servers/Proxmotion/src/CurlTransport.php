<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp;

/**
 * HTTP through PHP's curl extension (required by WISECP).
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 30,
    ) {}

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method === '' ? 'GET' : $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (! is_string($response)) {
            return ['status' => 0, 'body' => curl_error($curl)];
        }

        return ['status' => $status, 'body' => $response];
    }
}
