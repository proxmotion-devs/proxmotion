<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base of the billing API controllers (docs/adr/0008).
 */
abstract class ApiController extends Controller
{
    /**
     * The token of the request (set by AuthenticateApiToken).
     */
    protected function token(Request $request): ApiToken
    {
        $token = $request->attributes->get('api_token');

        return $token instanceof ApiToken ? $token : abort(401);
    }

    /**
     * 202 with the queued task; the client polls GET /tasks/{id}.
     */
    protected function accepted(Task $task): JsonResponse
    {
        return TaskResource::make($task->refresh()->load('server'))->response()->setStatusCode(202);
    }

    protected function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    /**
     * 409: the operation does not fit the server's current status.
     */
    protected function notPossible(Server $server): JsonResponse
    {
        return $this->conflict("This action is not possible while the server is {$server->status->value}.");
    }
}
