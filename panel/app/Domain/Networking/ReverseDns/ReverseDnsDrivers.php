<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

use App\Models\DnsProvider;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Builds the driver of a DNS provider. A pool without a provider is "record only".
 */
final readonly class ReverseDnsDrivers
{
    public function __construct(private HttpFactory $http) {}

    public function for(?DnsProvider $provider): ReverseDnsDriver
    {
        return match ($provider?->driver) {
            'powerdns' => new PowerDnsDriver(
                $this->http,
                (string) ($provider->options['api_url'] ?? ''),
                (string) ($provider->options['server_id'] ?? 'localhost'),
                (string) ($provider->credentials['api_key'] ?? ''),
            ),
            default => new RecordOnlyDriver,
        };
    }
}
