<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiScope;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "api.scope:servers:write": the token must have the scope.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken || ! $token->hasScope(ApiScope::from($scope))) {
            return response()->json(['message' => "This token does not have the {$scope} scope."], 403);
        }

        return $next($request);
    }
}
