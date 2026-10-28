<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Usage\UsageHistory;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A server's usage over the last day, week, month or year, with its traffic
 * per hour, day or period (docs/adr/0050).
 */
class ServerUsageController extends ApiController
{
    public function __invoke(Request $request, Server $server, UsageHistory $history): JsonResponse
    {
        $range = $request->validate(['range' => ['sometimes', 'string', Rule::in(array_keys(UsageHistory::RANGES))]])['range'] ?? UsageHistory::DEFAULT_RANGE;

        return response()->json(['data' => $history->for($server, $range)]);
    }
}
