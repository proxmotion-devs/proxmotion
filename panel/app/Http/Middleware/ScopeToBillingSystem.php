<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Task;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing API (docs/adr/0008): a token only reaches servers (and their
 * tasks) of its own billing system. Others answer 404 as if they did not
 * exist. Runs right after route model binding, before idempotency.
 */
class ScopeToBillingSystem
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('api_token');
        $route = $request->route();

        if ($token instanceof ApiToken && $route !== null) {
            $server = $route->parameter('server');
            $task = $route->parameter('task');

            // The same exception as a failed route model binding, so the answer is identical.
            if ($task instanceof Task && $task->server->billing_system_id !== $token->billing_system_id) {
                throw (new ModelNotFoundException)->setModel(Task::class, [$task->uuid]);
            }

            if ($server instanceof Server && $server->billing_system_id !== $token->billing_system_id) {
                throw (new ModelNotFoundException)->setModel(Server::class, [$server->uuid]);
            }
        }

        return $next($request);
    }
}
