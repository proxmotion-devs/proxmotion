<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Usage\DiskSpace;
use App\Domain\Usage\LiveStats;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The live figures of a server page (docs/adr/0036). Each request also marks the server
 * as watched, so servers:stats sends its figures to the page's channel. Disk space comes
 * only with these requests, from the guest agent of a running VM (§7).
 */
class ServerStatsController extends Controller
{
    public function __invoke(Request $request, Server $server, LiveStats $stats, DiskSpace $disk): JsonResponse
    {
        if (! LiveStats::available($server)) {
            return response()->json(['stats' => null, 'disk' => null, 'history' => []]);
        }

        $stats->watch($server);
        $current = $stats->current($server);

        return response()->json([
            'stats' => $current,
            'disk' => $current !== null && $current['status'] === 'running' ? $disk->current($server) : null,
            'history' => $request->boolean('history') ? $stats->history($server) : [],
        ]);
    }
}
