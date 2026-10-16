<?php

declare(strict_types=1);

namespace App\Domain\Networking\ReverseDns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * PowerDNS Authoritative HTTP API (/api/v1, X-API-Key). PTRs are replaced or
 * deleted as one rrset each in the pool's reverse zone, which must already
 * exist on the server. Not yet verified against a real PowerDNS server.
 */
final readonly class PowerDnsDriver implements ReverseDnsDriver
{
    public const TTL = 3600;

    public function __construct(
        private HttpFactory $http,
        private string $apiUrl,
        private string $serverId,
        #[\SensitiveParameter] private string $apiKey,
    ) {}

    public function publishes(): bool
    {
        return true;
    }

    public function set(string $zone, string $ptrName, string $hostname): void
    {
        $this->patch($zone, [
            'name' => "{$ptrName}.",
            'type' => 'PTR',
            'ttl' => self::TTL,
            'changetype' => 'REPLACE',
            'records' => [['content' => rtrim($hostname, '.').'.', 'disabled' => false]],
        ]);
    }

    public function remove(string $zone, string $ptrName): void
    {
        $this->patch($zone, ['name' => "{$ptrName}.", 'type' => 'PTR', 'changetype' => 'DELETE']);
    }

    public function test(): void
    {
        $this->send(fn (PendingRequest $request) => $request->get($this->serverPath()));
    }

    /**
     * @param  array<string, mixed>  $rrset
     */
    private function patch(string $zone, array $rrset): void
    {
        $this->send(fn (PendingRequest $request) => $request->patch(
            $this->serverPath().'/zones/'.rawurlencode(rtrim($zone, '.').'.'),
            ['rrsets' => [$rrset]],
        ));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): void
    {
        $request = $this->http
            ->baseUrl(rtrim($this->apiUrl, '/'))
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15);

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            throw new ReverseDnsFailed('The PowerDNS API could not be reached.');
        }

        if ($response->failed()) {
            $error = $response->json('error');
            $detail = is_string($error) && $error !== '' ? ": {$error}" : '';

            throw new ReverseDnsFailed("PowerDNS answered HTTP {$response->status()}{$detail}");
        }
    }

    private function serverPath(): string
    {
        return '/api/v1/servers/'.rawurlencode($this->serverId);
    }
}
