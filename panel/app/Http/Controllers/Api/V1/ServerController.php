<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Apps\AppNotEligible;
use App\Domain\Servers\HostnameChanger;
use App\Domain\Servers\ReinstallLimitReached;
use App\Domain\Servers\ReinstallLimits;
use App\Domain\Servers\ServerPower;
use App\Domain\Servers\ServerReinstaller;
use App\Domain\Usage\TrafficPeriods;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Http\Requests\Api\V1\ChangeResourcesRequest;
use App\Http\Requests\Api\V1\CreateServerRequest;
use App\Http\Requests\Api\V1\ReinstallServerRequest;
use App\Http\Requests\HostnameRequest;
use App\Http\Requests\PrimaryAddressRequest;
use App\Http\Resources\Api\V1\ServerResource;
use App\Models\AuditLog;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\PrimaryAddressChanger;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Servers of the billing system: create, look up, and the operations that
 * change their state. Every write is queued as a task and audited with the token.
 */
class ServerController extends ApiController
{
    private const WITH = ['package:id,uuid,name,reinstall_limit,reinstall_period_days', 'location:id,uuid,name', 'osTemplate:id,uuid,operating_system_version_id,name', 'ipAddresses.pool'];

    public function __construct(private readonly TaskRunner $tasks) {}

    /**
     * What a server answer needs, with the traffic of the current period only.
     *
     * @return array<int|string, mixed>
     */
    private static function with(): array
    {
        $period = TrafficPeriods::start()->toDateString();

        return [...self::WITH, 'trafficPeriods' => fn ($query) => $query->whereDate('period_start', $period)];
    }

    /**
     * Filter by external_id (the service id) or external_client_id.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'external_id' => ['nullable', 'string', 'max:191'],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', Rule::enum(ServerStatus::class)],
        ]);

        $servers = Server::query()
            ->where('billing_system_id', $this->token($request)->billing_system_id)
            ->with(self::with())
            ->when($filters['external_id'] ?? null, fn ($query, $id) => $query->where('external_id', $id))
            ->when($filters['external_client_id'] ?? null, fn ($query, $id) => $query->where('external_client_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return ServerResource::collection($servers);
    }

    public function store(CreateServerRequest $request, ServerCreator $creator): JsonResponse
    {
        $token = $this->token($request);

        try {
            $server = $creator->create($request->toNewServer($token->billingSystem), $token);
        } catch (AppNotEligible $e) {
            return $this->appNotEligible($e);
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        } catch (ProxmoxException) {
            return response()->json(['message' => 'Proxmox could not be reached. Try again later.'], 503);
        }

        AuditLog::record('server.created', $token, $server, ['hostname' => $server->hostname, 'external_id' => $server->external_id], $request->ip());

        return $this->accepted($server->tasks()->latest('id')->firstOrFail());
    }

    public function show(Server $server, ServerPower $power, ReinstallLimits $reinstalls): ServerResource
    {
        $server->load(self::with());

        return ServerResource::make($server)
            ->withPowerState($server->status === ServerStatus::Active ? $power->state($server) : null)
            ->withReinstalls($reinstalls->usage($server));
    }

    public function suspend(Request $request, Server $server): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        // Suspended by the panel over its traffic quota or a usage limit: the suspension becomes
        // the billing system's, so the panel never unsuspends it by itself (docs/adr/0039 §8).
        if ($server->status === ServerStatus::Suspended && $server->suspension_cause !== null) {
            $server->forceFill(['suspension_cause' => null, 'suspension_reason' => $reason ?? $server->suspension_reason])->save();
            AuditLog::record('server.suspended', $this->token($request), $server, ['hostname' => $server->hostname, 'taken_over' => true], $request->ip());

            return ServerResource::make($server->load(self::with()))->response();
        }

        return $this->startTask($request, $server, TaskType::Suspend, [ServerStatus::Active], 'server.suspended', function () use ($server, $reason) {
            // Shown in the admin panel; cleared again when the server is unsuspended.
            $server->forceFill(['suspension_reason' => $reason])->save();
        });
    }

    public function unsuspend(Request $request, Server $server): JsonResponse
    {
        return $this->startTask($request, $server, TaskType::Unsuspend, [ServerStatus::Suspended], 'server.unsuspended');
    }

    /**
     * Terminate: the VM and its disks are deleted, the addresses released.
     */
    public function destroy(Request $request, Server $server): JsonResponse
    {
        return $this->startTask($request, $server, TaskType::Terminate, [ServerStatus::Active, ServerStatus::Suspended, ServerStatus::Error], 'server.terminated');
    }

    public function power(Request $request, Server $server, ServerPower $power): JsonResponse
    {
        $action = PowerAction::from($request->validate(['action' => ['required', Rule::enum(PowerAction::class)]])['action']);

        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($power->request($server, $action, $this->token($request), $request->ip()));
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }
    }

    public function reinstall(ReinstallServerRequest $request, Server $server, ServerReinstaller $reinstaller): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($reinstaller->request($server, $request->osTemplate(), $request->sshKeys(), $this->token($request), $request->ip(), $request->app(), $request->appInputs()));
        } catch (AppNotEligible $e) {
            return $this->appNotEligible($e);
        } catch (ReinstallLimitReached $e) {
            // docs/adr/0045 §6
            return response()->json([
                'message' => $e->translatedMessage(),
                'error' => 'reinstall_limit_reached',
                'available_at' => $e->availableAt?->toIso8601String(),
            ], 409);
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }
    }

    /**
     * The chosen app does not fit the new system: a validation error of "app".
     */
    private function appNotEligible(AppNotEligible $e): JsonResponse
    {
        return response()->json(['message' => $e->translatedMessage(), 'errors' => ['app' => [$e->translatedMessage()]]], 422);
    }

    /**
     * Upgrade or downgrade: new resources, applied by a task. CPU and memory
     * of a running server take effect at its next restart unless "reboot".
     */
    public function changeResources(ChangeResourcesRequest $request, Server $server, ResourceChanger $changer): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($changer->request($server, $request->toChange(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            // Not enough capacity on the server's node, or no free addresses.
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }

    /**
     * Rename the server (docs/adr/0012): at once in the panel and its PTRs
     * that equal the old name, inside the server through the guest agent.
     */
    public function hostname(HostnameRequest $request, Server $server, HostnameChanger $changer): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($changer->request($server, $request->newHostname(), $request->reboot(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }

    /**
     * Make another address the primary one of its version (docs/adr/0011):
     * one of the server's additional addresses (swapped) or a free address of
     * its pools (the old primary is released). The address count never changes.
     */
    public function primaryAddress(PrimaryAddressRequest $request, Server $server, PrimaryAddressChanger $changer): JsonResponse
    {
        if ($server->status !== ServerStatus::Active) {
            return $this->notPossible($server);
        }

        try {
            return $this->accepted($changer->request($server, $request->ipVersion(), $request->address(), $this->token($request), $request->ip()));
        } catch (TaskAlreadyRunning $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }
    }

    /**
     * @param  list<ServerStatus>  $allowedFrom
     * @param  (callable(): void)|null  $queued  runs once the task is queued
     */
    private function startTask(Request $request, Server $server, TaskType $type, array $allowedFrom, string $auditAction, ?callable $queued = null): JsonResponse
    {
        if (! in_array($server->status, $allowedFrom, true)) {
            return $this->notPossible($server);
        }

        try {
            $task = $this->tasks->start($server, $type, $this->token($request));
        } catch (ProvisioningException $e) {
            return $this->conflict($e->translatedMessage());
        }

        if ($queued !== null) {
            $queued();
        }

        AuditLog::record($auditAction, $this->token($request), $server, ['hostname' => $server->hostname], $request->ip());

        return $this->accepted($task);
    }
}
