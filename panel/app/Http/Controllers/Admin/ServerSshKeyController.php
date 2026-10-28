<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\GuestSshKeys;
use App\Domain\Servers\GuestSshKeysUnavailable;
use App\Domain\Servers\ServerSshKeys;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SshKeysRequest;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * A server's SSH keys on its admin page (docs/adr/0049): the same whole-list
 * changes customers make through the billing API.
 */
class ServerSshKeyController extends Controller
{
    public function __construct(private readonly ServerSshKeys $sshKeys) {}

    public function update(SshKeysRequest $request, Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $this->sshKeys->change($server, $request->sshKeys(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Changing the SSH keys…');

        return back();
    }

    /**
     * The keys really inside the server, read through the guest agent
     * (docs/adr/0049 §11). Only reads.
     */
    public function guest(Server $server, GuestSshKeys $guestKeys): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return response()->json(['message' => __('This action is not possible while the server is :status.', ['status' => $server->status->value])], 422);
        }

        try {
            return response()->json($guestKeys->read($server));
        } catch (GuestSshKeysUnavailable $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }
}
