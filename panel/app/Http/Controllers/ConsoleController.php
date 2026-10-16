<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Servers\ConsoleSessions;
use App\Models\Server;
use App\Models\User;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * The noVNC console page of the admin panel. Every page load (and every
 * reconnect) opens a new console session. Customers will reach the console
 * through a signed single-use link from the billing API (docs/adr/0007).
 */
class ConsoleController extends Controller
{
    public function __invoke(Request $request, Server $server, ConsoleSessions $sessions, #[CurrentUser] User $user): Response
    {
        Gate::authorize('console', $server);

        $console = null;
        $error = null;

        try {
            $ticket = $sessions->open($server, $user, $request->ip());
            $console = [
                'url' => $this->websocketUrl($request).'?'.http_build_query(['ticket' => $ticket->ticket]),
                'password' => $ticket->password,
                'expires_at' => $ticket->expiresAt->toIso8601String(),
            ];
        } catch (ProxmoxException|UnexpectedValueException $e) {
            report($e);
            $error = __('The console could not be opened. Make sure the server is running.');
        }

        return Inertia::render('console/Show', [
            'server' => ['uuid' => $server->uuid, 'hostname' => $server->hostname],
            'console' => $console,
            'error' => $error,
            'idleMinutes' => (int) config('proxmotion.console.idle_minutes'),
            'backUrl' => route('admin.servers.show', $server),
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
