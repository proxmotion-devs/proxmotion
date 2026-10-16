<?php

namespace Proxmotion\Whmcs;

/**
 * HTTP through PHP's curl extension (always available on WHMCS servers).
 */
class CurlTransport implements Transport
{
    /** @var bool */
    private $verifyTls;

    /** @var int seconds */
    private $timeout;

    public function __construct(bool $verifyTls = true, int $timeout = 30)
    {
        $this->verifyTls = $verifyTls;
        $this->timeout = $timeout;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
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
        $error = curl_error($curl);

        // A no-op since PHP 8.0 and deprecated later; still needed on PHP 7.
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }

        if (!is_string($response)) {
            return ['status' => 0, 'body' => $error];
        }

        return ['status' => $status, 'body' => $response];
    }
}
