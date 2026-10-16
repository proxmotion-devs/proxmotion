<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Servers\ServerPower;
use App\Enums\DriftType;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Node;
use App\Models\ServerDrift;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\TaskRunner;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The drift report (ADR 0005): nothing is repaired automatically, every
 * action here is an admin's decision and is audited.
 */
class DriftController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/drifts/Index', [
            'drifts' => ServerDrift::query()
                ->visible()
                ->with(['cluster:id,uuid,name', 'server:id,uuid,hostname,status'])
                ->latest('first_seen_at')
                ->paginate(50)
                ->through(fn (ServerDrift $drift) => self::present($drift)),
        ]);
    }

    /**
     * Take Proxmox's values: the node a VM was moved to, or its resources.
     */
    public function accept(Request $request, ServerDrift $drift, #[CurrentUser] User $admin): RedirectResponse
    {
        $server = $drift->server;
        abort_if($server === null || $drift->resolved_at !== null, 404);

        if ($drift->type === DriftType::NodeMismatch) {
            $node = Node::query()
                ->where('cluster_id', $drift->cluster_id)
                ->where('name', $drift->actual['node'] ?? '')
                ->first();

            if ($node === null) {
                $this->toast('error', 'The node is not known yet. Sync the cluster first.');

                return back();
            }

            $server->forceFill(['node_id' => $node->id])->save();
        } elseif ($drift->type === DriftType::ResourceMismatch) {
            $server->forceFill([
                'cpu_cores' => (int) ($drift->actual['cpu_cores'] ?? $server->cpu_cores),
                'memory_mib' => (int) ($drift->actual['memory_mib'] ?? $server->memory_mib),
                'disk_mib' => (int) ($drift->actual['disk_mib'] ?? $server->disk_mib),
            ])->save();
        } else {
            abort(422);
        }

        $drift->resolve('accepted', $admin);
        $this->audit('drift.accepted', $drift, $admin, $request);
        $this->toast('success', 'The panel now uses the values from Proxmox.');

        return back();
    }

    /**
     * The VM is gone: put the server into error, so it can be deleted (which frees its IPs).
     */
    public function markError(Request $request, ServerDrift $drift, #[CurrentUser] User $admin): RedirectResponse
    {
        $server = $drift->server;
        abort_if($server === null || $drift->type !== DriftType::MissingVm || $drift->resolved_at !== null, 404);

        $server->forceFill(['status' => ServerStatus::Error])->save();
        $drift->resolve('marked_error', $admin);
        $this->audit('drift.marked_error', $drift, $admin, $request);
        $this->toast('success', 'The server is marked as failed. Delete it to free its IP addresses.');

        return back();
    }

    /**
     * Bring the VM back to the panel's power state: stop a suspended server's
     * VM again, or start an active server's VM. The next sync closes the drift.
     */
    public function applyPower(Request $request, ServerDrift $drift, #[CurrentUser] User $admin, TaskRunner $tasks, ServerPower $power): RedirectResponse
    {
        $server = $drift->server;
        abort_if($server === null || $drift->type !== DriftType::PowerMismatch || $drift->resolved_at !== null, 404);

        try {
            match ($server->status) {
                ServerStatus::Suspended => $tasks->start($server, TaskType::Suspend, $admin),
                ServerStatus::Active => $power->request($server, PowerAction::Start, $admin, $request->ip()),
                default => abort(422),
            };
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->audit('drift.power_applied', $drift, $admin, $request);
        $this->toast('info', 'The power state is being applied.');

        return back();
    }

    /**
     * Hide the drift while it lasts; it closes by itself once it is gone.
     */
    public function dismiss(Request $request, ServerDrift $drift, #[CurrentUser] User $admin): RedirectResponse
    {
        abort_if($drift->resolved_at !== null, 404);

        $drift->forceFill(['dismissed_at' => now()])->save();
        $this->audit('drift.dismissed', $drift, $admin, $request);
        $this->toast('success', 'Drift dismissed.');

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ServerDrift $drift): array
    {
        return [
            'uuid' => $drift->uuid,
            'type' => $drift->type,
            'vmid' => $drift->vmid,
            'expected' => $drift->expected,
            'actual' => $drift->actual,
            'first_seen_at' => $drift->first_seen_at->toIso8601String(),
            'last_seen_at' => $drift->last_seen_at->toIso8601String(),
            'cluster' => ['uuid' => $drift->cluster->uuid, 'name' => $drift->cluster->name],
            'server' => $drift->server !== null
                ? ['uuid' => $drift->server->uuid, 'hostname' => $drift->server->hostname, 'status' => $drift->server->status]
                : null,
        ];
    }

    private function audit(string $action, ServerDrift $drift, User $admin, Request $request): void
    {
        AuditLog::record($action, $admin, $drift->server ?? $drift, [
            'type' => $drift->type->value,
            'vmid' => $drift->vmid,
            'expected' => $drift->expected,
            'actual' => $drift->actual,
        ], $request->ip());
    }
}
