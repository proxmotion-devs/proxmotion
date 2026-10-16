<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the console proxy service, which knows the shared secret, may call the
 * internal console endpoints.
 */
class AuthenticateConsoleProxy
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('proxmotion.console.proxy_secret');
        $given = $request->header('X-Console-Proxy-Secret');

        if (! is_string($secret) || $secret === '') {
            abort(503, 'The console proxy secret is not configured.');
        }

        if (! is_string($given) || ! hash_equals($secret, $given)) {
            abort(401);
        }

        return $next($request);
    }
}
