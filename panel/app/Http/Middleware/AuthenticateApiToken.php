<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing API authentication (docs/adr/0008): a Bearer token that is not
 * revoked or expired, used from an allowed address. The token is available
 * to controllers as the request attribute "api_token" (see ApiActor).
 */
class AuthenticateApiToken
{
    /** last_used_at is written at most this often per token. */
    private const TOUCH_SECONDS = 60;

    /** Failed attempts per address and minute before requests are refused with 429. */
    private const MAX_FAILURES = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'api-auth-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, self::MAX_FAILURES)) {
            return response()->json(['message' => 'Too many failed attempts. Try again later.'], 429, [
                'Retry-After' => (string) RateLimiter::availableIn($failures),
            ]);
        }

        $secret = $request->bearerToken();
        $token = is_string($secret) && $secret !== '' ? ApiToken::findActive($secret) : null;

        if ($token === null) {
            RateLimiter::hit($failures);

            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        if (! $token->allowsIp($request->ip())) {
            return response()->json(['message' => 'This token may not be used from your address.'], 403);
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subSeconds(self::TOUCH_SECONDS)) || $token->last_used_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
