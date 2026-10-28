<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Servers\ServerSshKeys;
use App\Enums\ServerStatus;
use App\Http\Requests\SshKeysRequest;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use Illuminate\Http\JsonResponse;

/**
 * The SSH keys the panel manages on a server (docs/adr/0049): read them, or
 * replace the whole list. A change answers 202 with the change_ssh_keys
 * task; the list changes once the task succeeded.
 */
class SshKeyController extends ApiController
{
    public function __construct(private readonly ServerSshKeys $sshKeys) {}

    public function show(Server $server): JsonResponse
    {
        return response()->json(['data' => ['ssh_keys' => ServerSshKeys::describe($server), 'max_keys' => ServerSshKeys::MAX_KEYS]]);
    }

    public function update(SshKeysRequest $request, Server $server): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($this->sshKeys->change($server, $request->sshKeys(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }
}
