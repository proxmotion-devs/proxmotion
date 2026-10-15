<?php

declare(strict_types=1);

namespace App\Proxmox;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Everything needed to talk to one Proxmox cluster or standalone node.
 */
final readonly class ProxmoxConnection
{
    /**
     * @param  list<string>  $hosts  API endpoints, tried in order (cluster failover)
     */
    public function __construct(
        public array $hosts,
        public int $port,
        public string $tokenId,
        #[SensitiveParameter] public string $tokenSecret,
        public bool $verifyTls = true,
        public int $timeoutSeconds = 20,
    ) {
        if ($hosts === []) {
            throw new InvalidArgumentException('At least one Proxmox host is required.');
        }
    }

    public function baseUrl(string $host): string
    {
        // Bare IPv6 addresses must be wrapped in brackets inside a URL.
        $hostPart = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;

        return "https://{$hostPart}:{$this->port}/api2/json";
    }

    public function authorizationHeader(): string
    {
        return "PVEAPIToken={$this->tokenId}={$this->tokenSecret}";
    }

    /**
     * Keep the secret out of dumps and logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'hosts' => $this->hosts,
            'port' => $this->port,
            'tokenId' => $this->tokenId,
            'tokenSecret' => '********',
            'verifyTls' => $this->verifyTls,
        ];
    }
}
