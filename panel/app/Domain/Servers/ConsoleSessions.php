<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Str;

/**
 * Browser console sessions (docs/adr/0002). Opening a console asks Proxmox for a
 * VNC listener and hides its details behind a short-lived, single-use ticket that
 * only the console proxy can redeem. One session per server: opening a new one
 * ends the previous one at its next heartbeat.
 */
final readonly class ConsoleSessions
{
    public function __construct(
        private ProxmoxClientFactory $clients,
        private Cache $cache,
        private Encrypter $encrypter,
    ) {}

    /**
     * @throws ProxmoxException when Proxmox refuses (e.g. the VM is not running)
     */
    public function open(Server $server, User $user, ?string $ipAddress = null): ConsoleTicket
    {
        $server->loadMissing(['cluster', 'node']);

        $vnc = $this->clients->forCluster($server->cluster)->qemu($server->node->name)->vncProxy($server->vmid);

        $ticket = Str::random(64);
        $session = Str::random(40);
        $expiresAt = Carbon::now()->addMinutes($this->maxMinutes());
        $connection = $server->cluster->connection();
        $path = '/nodes/'.rawurlencode($server->node->name)."/qemu/{$server->vmid}/vncwebsocket?".http_build_query([
            'port' => $vnc->port,
            'vncticket' => $vnc->ticket,
        ]);

        $this->cache->put($this->ticketKey($ticket), $this->encrypter->encryptString((string) json_encode([
            'session' => $session,
            'server' => $server->uuid,
            'expires_at' => $expiresAt->toIso8601String(),
            'upstream' => [
                // Each cluster host in failover order; pveproxy forwards to the VM's node.
                'urls' => array_map(
                    fn (string $host) => (string) preg_replace('#^https://#', 'wss://', $connection->baseUrl($host)).$path,
                    $connection->hosts,
                ),
                'authorization' => $connection->authorizationHeader(),
                'verify_tls' => $connection->verifyTls,
            ],
        ])), (int) config('proxmotion.console.ticket_seconds'));

        $this->cache->put($this->sessionKey($session), ['server_id' => $server->id, 'user_id' => $user->id], $expiresAt);
        $this->cache->put($this->currentSessionKey($server), $session, $expiresAt);

        AuditLog::record('console.opened', $user, $server, ['hostname' => $server->hostname], $ipAddress);

        return new ConsoleTicket($ticket, $vnc->password, $expiresAt);
    }

    /**
     * Exchange a ticket for the upstream connection details. Works only once.
     *
     * @return array<string, mixed>|null
     */
    public function redeem(string $ticket): ?array
    {
        $key = $this->ticketKey($ticket);

        $payload = CacheFacade::lock("{$key}:lock", 5)->get(fn () => $this->cache->pull($key));

        if (! is_string($payload)) {
            return null;
        }

        $data = json_decode($this->encrypter->decryptString($payload), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Whether a session may continue: it has not expired, it is still the
     * newest session of its server, the server is active and the user may
     * still open its console.
     */
    public function isActive(string $session): bool
    {
        $state = $this->cache->get($this->sessionKey($session));

        if (! is_array($state) || ! is_int($state['server_id'] ?? null) || ! is_int($state['user_id'] ?? null)) {
            return false;
        }

        $server = Server::query()->find($state['server_id']);
        $user = User::query()->find($state['user_id']);

        if ($server === null || $user === null || $this->cache->get($this->currentSessionKey($server)) !== $session) {
            return false;
        }

        return $server->status === ServerStatus::Active && $user->can('console', $server);
    }

    private function maxMinutes(): int
    {
        return max(1, (int) config('proxmotion.console.max_minutes'));
    }

    private function ticketKey(string $ticket): string
    {
        return 'console:ticket:'.hash('sha256', $ticket);
    }

    private function sessionKey(string $session): string
    {
        return 'console:session:'.hash('sha256', $session);
    }

    private function currentSessionKey(Server $server): string
    {
        return "console:server:{$server->id}";
    }
}
