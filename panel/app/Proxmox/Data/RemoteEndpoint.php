<?php

declare(strict_types=1);

namespace App\Proxmox\Data;

use SensitiveParameter;

/**
 * The target of a remote migration (`target-endpoint`, docs/adr/0037 §5):
 * a node of another cluster and the incoming move token of that cluster.
 * The VM lands on the node that answers at $host.
 */
final readonly class RemoteEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public string $tokenId,
        #[SensitiveParameter] public string $tokenSecret,
        public ?string $fingerprint = null,
    ) {}

    /**
     * The property string Proxmox expects:
     * apitoken=PVEAPIToken=user@realm!token=SECRET,host=ADDRESS[,fingerprint=…][,port=…].
     */
    public function toParameter(): string
    {
        $parts = [
            "apitoken=PVEAPIToken={$this->tokenId}={$this->tokenSecret}",
            "host={$this->host}",
            "port={$this->port}",
        ];

        if ($this->fingerprint !== null) {
            $parts[] = "fingerprint={$this->fingerprint}";
        }

        return implode(',', $parts);
    }

    /**
     * $text without the token secret, for error messages that might repeat it.
     */
    public function mask(string $text): string
    {
        return $this->tokenSecret === '' ? $text : str_replace($this->tokenSecret, '********', $text);
    }

    /**
     * Keep the secret out of dumps and logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'tokenId' => $this->tokenId,
            'tokenSecret' => '********',
            'fingerprint' => $this->fingerprint,
        ];
    }
}
