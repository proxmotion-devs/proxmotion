<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveServerRequest;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\Moves\ServerMover;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Moving a server to another node, cluster or location (docs/adr/0037).
 */
class ServerMoveController extends Controller
{
    public function store(MoveServerRequest $request, Server $server, #[CurrentUser] User $admin, ServerMover $mover): RedirectResponse
    {
        try {
            $mover->request($server, $request->toTarget(), $admin, $request->ip());
        } catch (TaskAlreadyRunning $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['move' => $e->translatedMessage()]);
        } catch (ProxmoxException $e) {
            throw ValidationException::withMessages(['move' => __('Could not connect to Proxmox: :error', ['error' => $e->getMessage()])]);
        }

        $this->toast('info', 'Moving the server…');

        return back();
    }

    /**
     * Run the remaining steps of a move whose VM Proxmox already moved (docs/adr/0037 §7).
     */
    public function finish(Request $request, Server $server, ServerMove $move, #[CurrentUser] User $admin, ServerMover $mover): RedirectResponse
    {
        abort_unless($move->server_id === $server->id, 404);

        try {
            $mover->finish($move, $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Finishing the move…');

        return back();
    }
}
