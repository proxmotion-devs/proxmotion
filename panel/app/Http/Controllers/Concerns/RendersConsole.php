<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Servers\ConsoleSessions;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\User;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * The noVNC console page. Every page load (and every reconnect) opens a new
 * console session (docs/adr/0002).
 */
trait RendersConsole
{
    protected function consolePage(Request $request, Server $server, User|ApiToken $actor, ?string $backUrl): Response
    {
        $console = null;
        $error = null;

        try {
            $ticket = app(ConsoleSessions::class)->open($server, $actor, $request->ip());
            $console = [
                'url' => $this->websocketUrl($request).'?'.http_build_query(['ticket' => $ticket->ticket]),
                'password' => $ticket->password,
                'expires_at' => $ticket->expiresAt->toIso8601String(),
            ];
        } catch (ProxmoxException|UnexpectedValueException $e) {
            report($e);
            $error = __('The console could not be opened. Make sure the server is running.');
        }

        return $this->renderConsole($server, $console, $error, $backUrl);
    }

    /**
     * @param  array{url: string, password: string, expires_at: string}|null  $console
     * @param  array<mixed>|string|null  $error  translated, as __() returns it
     */
    protected function renderConsole(Server $server, ?array $console, array|string|null $error, ?string $backUrl): Response
    {
        return Inertia::render('console/Show', [
            'server' => ['uuid' => $server->uuid, 'hostname' => $server->hostname],
            'console' => $console,
            'error' => $error,
            'idleMinutes' => (int) config('proxmotion.console.idle_minutes'),
            'backUrl' => $backUrl,
        ]);
    }

    private function websocketUrl(Request $request): string
    {
        $configured = config('proxmotion.console.websocket_url');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return ($request->isSecure() ? 'wss' : 'ws').'://'.$request->getHttpHost().'/console-ws';
    }
}
