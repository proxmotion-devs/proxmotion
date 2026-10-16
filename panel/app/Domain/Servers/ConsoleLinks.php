<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Server;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Signed, single-use links that open one server's console for a customer of
 * the billing system (docs/adr/0008). A link is valid for 60 seconds and
 * works once; opening it is what grants the browser the console.
 */
final readonly class ConsoleLinks
{
    public const VALID_SECONDS = 60;

    public function __construct(private Cache $cache) {}

    /**
     * @return array{url: string, expires_at: Carbon}
     */
    public function issue(Server $server, ApiToken $token, ?string $ipAddress): array
    {
        $nonce = Str::random(48);
        $expiresAt = Carbon::now()->addSeconds(self::VALID_SECONDS);

        $this->cache->put($this->key($nonce), ['server_id' => $server->id, 'api_token_id' => $token->id], $expiresAt);

        AuditLog::record('console.link_issued', $token, $server, ['hostname' => $server->hostname], $ipAddress);

        return [
            'url' => URL::temporarySignedRoute('console.link', $expiresAt, ['server' => $server->uuid, 'nonce' => $nonce]),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Use up a link: the token that issued it, if the link is unused, belongs
     * to this server and the token may still open consoles.
     */
    public function redeem(Server $server, string $nonce): ?ApiToken
    {
        $link = $this->cache->pull($this->key($nonce));

        if (! is_array($link) || ($link['server_id'] ?? null) !== $server->id) {
            return null;
        }

        return ApiToken::usableFor($link['api_token_id'] ?? null, ApiScope::ServersSecrets);
    }

    private function key(string $nonce): string
    {
        return 'console:link:'.hash('sha256', $nonce);
    }
}
