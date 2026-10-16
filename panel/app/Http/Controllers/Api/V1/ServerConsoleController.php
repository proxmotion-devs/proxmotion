<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Servers\ConsoleLinks;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A console link for the billing module to open in the customer's browser.
 */
class ServerConsoleController extends ApiController
{
    public function __invoke(Request $request, Server $server, ConsoleLinks $links): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        $link = $links->issue($server, $this->token($request), $request->ip());

        return response()->json(['data' => [
            'url' => $link['url'],
            'expires_at' => $link['expires_at']->toIso8601String(),
        ]], 201);
    }
}
