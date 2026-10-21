<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Usage\LiveStats;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live figures of a server page (docs/adr/0036). Each request also marks the server
 * as watched, so servers:stats sends its figures to the page's channel.
 */
class ServerStatsController extends Controller
{
    public function __invoke(Request $request, Server $server, LiveStats $stats): JsonResponse
    {
        if (! LiveStats::available($server)) {
            return response()->json(['stats' => null, 'history' => []]);
        }

        $stats->watch($server);

        return response()->json([
            'stats' => $stats->current($server),
            'history' => $request->boolean('history') ? $stats->history($server) : [],
        ]);
    }
}
