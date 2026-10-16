<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domain\Servers\GuestAgentProbe;
use App\Domain\Servers\ServerPasswords;
use App\Domain\Servers\ServerPower;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function __construct(private readonly ServerPower $power) {}

    /**
     * Show one of the customer's servers. Internal details (VMID, node,
     * cluster, storage, IP pools, task errors) stay on the admin page.
     */
    public function show(#[CurrentUser] User $user, Server $server, GuestAgentProbe $agent): Response|RedirectResponse
    {
        Gate::authorize('view', $server);

        if ($user->isAdmin()) {
            return to_route('admin.servers.show', $server);
        }

        $server->load(['location:id,name', 'package:id,name', 'osTemplate:id,name', 'ipAddresses.pool:id,version']);

        return Inertia::render('client/servers/Show', [
            'server' => [
                'uuid' => $server->uuid,
                'hostname' => $server->hostname,
                'status' => $server->status,
                'busy' => $server->status->isBusy(),
                'cpu_cores' => $server->cpu_cores,
                'memory_mib' => $server->memory_mib,
                'disk_mib' => $server->disk_mib,
                'port_speed_mbps' => $server->port_speed_mbps,
                'traffic_bytes' => $server->traffic_bytes,
                'location' => $server->location->name,
                'package' => $server->package?->name,
                'os_template' => $server->osTemplate?->name,
                'addresses' => $server->ipAddresses->map(fn (IpAddress $address) => [
                    'address' => $address->address,
                    'version' => $address->pool->version,
                ]),
                'created_at' => ($server->provisioned_at ?? $server->created_at)?->toIso8601String(),
                'password_unrevealed' => $server->root_password_unrevealed && $server->root_password !== null,
            ],
            // Progress only: step messages and errors may name nodes or storages.
            'tasks' => $server->tasks()->latest('id')->limit(5)->get()->map(fn (Task $task) => [
                'uuid' => $task->uuid,
                'type' => $task->type,
                'status' => $task->status,
                'steps' => array_map(
                    fn (array $step) => ['key' => $step['key'], 'status' => $step['status']],
                    $task->steps,
                ),
                'created_at' => $task->created_at?->toIso8601String(),
                'finished_at' => $task->finished_at?->toIso8601String(),
            ]),
            // Asked from Proxmox after the page has loaded, so a slow cluster does not block it.
            'power' => Inertia::defer(fn () => $server->status === ServerStatus::Active ? $this->power->state($server) : null),
            'agent' => Inertia::defer(fn () => $server->status === ServerStatus::Active ? $agent->reachable($server) : null),
            'revealedPassword' => session('revealed_password'),
        ]);
    }

    public function resetPassword(Request $request, #[CurrentUser] User $user, Server $server, ServerPasswords $passwords): RedirectResponse
    {
        Gate::authorize('resetPassword', $server);

        try {
            $passwords->requestReset($server, $user, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', 'Setting a new root password…');

        return back();
    }

    /**
     * Show the root password once, through the session flash of the next page.
     */
    public function revealPassword(Request $request, #[CurrentUser] User $user, Server $server, ServerPasswords $passwords): RedirectResponse
    {
        Gate::authorize('revealPassword', $server);

        return back()->with('revealed_password', $passwords->reveal($server, $user, $request->ip()));
    }

    public function power(Request $request, #[CurrentUser] User $user, Server $server, PowerAction $action): RedirectResponse
    {
        Gate::authorize('power', $server);

        try {
            $this->power->request($server, $action, $user, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', $action->startedMessage());

        return back();
    }
}
