<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Forwarded headers of a TLS proxy in front of the panel (docs/adr/0054).
 */
class TrustedProxiesTest extends TestCase
{
    public function test_forwarded_headers_count_only_from_trusted_proxies()
    {
        Route::get('/_test/client', fn () => ['ip' => request()->ip(), 'secure' => request()->secure()]);

        // An absolute address: the URL generator would keep https from the trusted request before.
        $request = fn () => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('http://localhost/_test/client');

        // Nobody is trusted by default: a client cannot choose its address.
        $request()->assertExactJson(['ip' => '10.0.0.5', 'secure' => false]);

        config(['proxmotion.trusted_proxies' => ' 192.0.2.1, 10.0.0.0/8 ']);
        $request()->assertExactJson(['ip' => '203.0.113.7', 'secure' => true]);

        config(['proxmotion.trusted_proxies' => '192.0.2.1']);
        $request()->assertExactJson(['ip' => '10.0.0.5', 'secure' => false]);

        config(['proxmotion.trusted_proxies' => '*']);
        $request()->assertExactJson(['ip' => '203.0.113.7', 'secure' => true]);
    }
}
