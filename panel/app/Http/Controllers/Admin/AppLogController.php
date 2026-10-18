<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppLogs;
use App\Domain\Apps\LiveAppPolls;
use App\Http\Controllers\Controller;
use App\Models\AppLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The log of an app script (docs/adr/0021), asked for every few seconds by
 * the log dialog of the server page. While the script runs, each request
 * first polls it like apps:poll does (LiveAppPolls), then answers with the
 * text the viewer is missing, the step and the progress.
 */
class AppLogController extends Controller
{
    public function show(Request $request, AppLog $appLog, AppLogs $logs, LiveAppPolls $live): JsonResponse
    {
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : null;
        $appLog->load(['installation.server', 'installation.version', 'run']);

        // The step and progress follow too, and a script that ended is finished.
        $live->log($appLog);
        $appLog->refresh();

        $owner = $appLog->run ?? $appLog->installation;

        return response()->json([
            ...$logs->since($appLog, $cursor),
            'live' => $appLog->isLive(),
            'status' => $owner->status,
            'progress' => $owner->progress,
            'step' => $owner->step,
        ]);
    }
}
