<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Networking\ReverseDns\ReverseDns;
use App\Enums\ServerStatus;
use App\Http\Requests\Api\V1\RdnsRequest;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/**
 * Set (or with an empty hostname remove) the PTR of one of the server's
 * public addresses. Publishing it through the DNS driver happens in the queue.
 */
class ServerRdnsController extends ApiController
{
    public function __invoke(RdnsRequest $request, Server $server, ReverseDns $reverseDns): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        $address = $request->ipAddress();
        $reverseDns->update($address, $request->hostname(), $this->token($request), $request->ip());

        return response()->json(['data' => ['address' => $address->address, 'ptr' => $address->refresh()->ptr]]);
    }
}
