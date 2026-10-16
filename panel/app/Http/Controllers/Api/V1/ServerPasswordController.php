<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Servers\ServerPasswords;
use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Root passwords (docs/adr/0007, 0008): a reset is queued like any other
 * operation; the new password (or the initial one) is handed out once.
 */
class ServerPasswordController extends ApiController
{
    /**
     * A random password (fetch it once with GET), or the one chosen in the
     * billing system ("root_password"), which then is not handed out again.
     */
    public function reset(Request $request, Server $server, ServerPasswords $passwords): JsonResponse
    {
        $chosen = $request->validate(['root_password' => ['nullable', 'string', 'min:12', 'max:128']])['root_password'] ?? null;

        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($passwords->requestReset($server, $this->token($request), $request->ip(), $chosen));
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }
    }

    /**
     * The current root password, if the billing system has not fetched it yet.
     * Only one of several concurrent requests gets it.
     */
    public function show(Request $request, Server $server): JsonResponse
    {
        $claimed = Server::query()
            ->whereKey($server->id)
            ->whereNotNull('root_password')
            ->where('root_password_unrevealed', true)
            ->update(['root_password_unrevealed' => false]);

        if ($claimed === 0) {
            return response()->json(['message' => 'The root password has already been fetched. Reset it to get a new one.'], 410);
        }

        AuditLog::record('server.password_fetched', $this->token($request), $server, ['hostname' => $server->hostname], $request->ip());

        return response()->json(['data' => ['root_password' => $server->refresh()->root_password]]);
    }
}
