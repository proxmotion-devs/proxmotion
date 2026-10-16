<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Domain\Servers\ConsoleSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints for the console proxy service (docs/adr/0002). Never called by browsers.
 */
class ConsoleProxyController extends Controller
{
    public function __construct(private readonly ConsoleSessions $sessions) {}

    public function redeem(Request $request): JsonResponse
    {
        $validated = $request->validate(['ticket' => ['required', 'string', 'max:128']]);

        $connection = $this->sessions->redeem($validated['ticket']);

        return $connection === null
            ? response()->json(['message' => 'Unknown or expired ticket.'], 404)
            : response()->json($connection);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate(['session' => ['required', 'string', 'max:128']]);

        return response()->json(['active' => $this->sessions->isActive($validated['session'])]);
    }
}
