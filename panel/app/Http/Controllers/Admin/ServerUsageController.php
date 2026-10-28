<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Usage\UsageHistory;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The usage graphs of a server page's Usage tab (docs/adr/0050).
 */
class ServerUsageController extends Controller
{
    public function __invoke(Request $request, Server $server, UsageHistory $history): JsonResponse
    {
        $range = $request->validate(['range' => ['sometimes', 'string', Rule::in(array_keys(UsageHistory::RANGES))]])['range'] ?? UsageHistory::DEFAULT_RANGE;

        return response()->json($history->for($server, $range));
    }
}
