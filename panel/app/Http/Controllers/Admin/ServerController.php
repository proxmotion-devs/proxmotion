<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\AppNotEligible;
use App\Domain\Apps\OrderedAppOptions;
use App\Domain\Apps\RecipeAction;
use App\Domain\Apps\RecipeInput;
use App\Domain\Servers\GuestAgentProbe;
use App\Domain\Servers\HostnameChanger;
use App\Domain\Servers\ReinstallOptions;
use App\Domain\Servers\ResourceOptions;
use App\Domain\Servers\ServerPasswords;
use App\Domain\Servers\ServerPower;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppInstallationStatus;
use App\Enums\PowerAction;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeResourcesRequest;
use App\Http\Requests\Admin\ServerRequest;
use App\Http\Requests\HostnameRequest;
use App\Http\Requests\PrimaryAddressRequest;
use App\Http\Requests\ReinstallRequest;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\BillingSystem;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\PrimaryAddressChanger;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskFailer;
use App\Provisioning\TaskRunner;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServerController extends Controller
{
    public function __construct(
        private readonly TaskRunner $tasks,
        private readonly ServerPower $power,
    ) {}

    public function index(Request $request): Response
    {
        $showTerminated = $request->boolean('terminated');

        return Inertia::render('admin/servers/Index', [
            'servers' => Server::query()
                ->when(! $showTerminated, fn ($query) => $query->occupying())
                ->with(['node:id,name', 'location:id,name', 'package:id,name', 'billingSystem:id,name', 'ipAddresses:id,server_id,address'])
                ->latest()
                ->paginate(50)
                ->withQueryString()
                ->through(fn (Server $server) => [
                    'uuid' => $server->uuid,
                    'hostname' => $server->hostname,
                    'status' => $server->status,
                    'vmid' => $server->vmid,
                    'billing_system' => $server->billingSystem?->name,
                    'external_id' => $server->external_id,
                    'external_client_id' => $server->external_client_id,
                    'node' => $server->node->name,
                    'location' => $server->location->name,
                    'package' => $server->package?->name,
                    'addresses' => $server->ipAddresses->pluck('address'),
                ]),
            'showTerminated' => $showTerminated,
        ]);
    }

    public function create(OrderedAppOptions $orderedApps): Response
    {
        $osTemplates = OsTemplate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return Inertia::render('admin/servers/Create', [
            'billingSystems' => BillingSystem::options(),
            'locations' => Location::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Location $location) => ['value' => $location->uuid, 'label' => $location->name]),
            'packages' => Package::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Package $package) => [
                    'value' => $package->uuid,
                    'label' => $package->name,
                    'network_models' => $package->network_models,
                ]),
            'osTemplates' => $osTemplates->map(fn (OsTemplate $template) => ['value' => $template->uuid, 'label' => $template->name]),
            // Apps that can come with the server (docs/adr/0016 §5).
            'apps' => fn () => $orderedApps->for($osTemplates),
        ]);
    }

    public function store(ServerRequest $request, ServerCreator $creator, #[CurrentUser] User $admin): RedirectResponse
    {
        try {
            $server = $creator->create($request->toNewServer(), $admin);
        } catch (AppNotEligible $e) {
            throw ValidationException::withMessages(['app' => $e->translatedMessage()]);
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['server' => $e->translatedMessage()]);
        } catch (ProxmoxException $e) {
            throw ValidationException::withMessages(['server' => __('Could not connect to Proxmox: :error', ['error' => $e->getMessage()])]);
        }

        $this->toast('success', 'Server :hostname is being created.', ['hostname' => $server->hostname]);

        return to_route('admin.servers.show', $server);
    }

    public function show(Server $server, GuestAgentProbe $agent, AppEligibility $eligibility, TaskFailer $failer): Response
    {
        $server->load(['node:id,uuid,name', 'cluster:id,uuid,name', 'location:id,name', 'package:id,name', 'billingSystem:id,name', 'osTemplate:id,name,family,version,platform', 'storage:id,name', 'ipAddresses.pool:id,uuid,name,version']);

        return Inertia::render('admin/servers/Show', [
            'server' => [
                'uuid' => $server->uuid,
                'hostname' => $server->hostname,
                'status' => $server->status,
                'origin' => $server->origin,
                'vmid' => $server->vmid,
                'network_model' => $server->network_model,
                'cpu_cores' => $server->cpu_cores,
                'memory_mib' => $server->memory_mib,
                'disk_mib' => $server->disk_mib,
                'port_speed_mbps' => $server->port_speed_mbps,
                'traffic_bytes' => $server->traffic_bytes,
                'has_password' => $server->root_password !== null,
                'busy' => $server->status->isBusy(),
                'reboot_required' => $server->reboot_required,
                'billing_system' => $server->billingSystem?->name,
                'external_id' => $server->external_id,
                'external_client_id' => $server->external_client_id,
                'cluster' => ['uuid' => $server->cluster->uuid, 'name' => $server->cluster->name],
                'node' => $server->node->name,
                'storage' => $server->storage?->name,
                'location' => $server->location->name,
                'package' => $server->package?->name,
                'os_template' => $server->osTemplate?->name,
                'platform' => $server->osTemplate->platform->value ?? 'linux',
                'addresses' => $server->ipAddresses->map(fn (IpAddress $address) => [
                    'address' => $address->address,
                    'version' => $address->pool->version,
                    'primary' => $server->ipAddresses->first(fn (IpAddress $other) => $other->pool->version === $address->pool->version)?->is($address) ?? false,
                    'pool' => ['uuid' => $address->pool->uuid, 'name' => $address->pool->name],
                    'ptr' => $address->ptr,
                    'ptr_pending' => $address->ptr !== null && $address->ptr_synced_at === null,
                ]),
                'provisioned_at' => $server->provisioned_at?->toIso8601String(),
                'created_at' => $server->created_at?->toIso8601String(),
            ],
            'tasks' => $server->tasks()->latest('id')->limit(10)->get()->map(fn (Task $task) => [
                'uuid' => $task->uuid,
                'type' => $task->type,
                'status' => $task->status,
                'steps' => $task->steps,
                'error' => $task->error,
                'created_at' => $task->created_at?->toIso8601String(),
                'finished_at' => $task->finished_at?->toIso8601String(),
                'can_fail' => $failer->canAbandon($task),
            ]),
            'revealedPassword' => session('revealed_password'),
            // Asked from Proxmox after the page has loaded, so a slow cluster does not block it.
            'power' => Inertia::defer(fn () => $server->status === ServerStatus::Active ? $this->power->state($server) : null),
            'reinstallOptions' => fn () => ReinstallOptions::for($server),
            'resourceOptions' => fn () => ResourceOptions::for($server),
            'drifts' => $server->drifts()->visible()->with(['cluster:id,uuid,name', 'server:id,uuid,hostname,status'])->get()
                ->map(fn (ServerDrift $drift) => DriftController::present($drift)),
            'agent' => Inertia::defer(fn () => $server->status === ServerStatus::Active ? $agent->reachable($server) : null),
            'apps' => $server->appInstallations()
                ->with(['recipe:id,slug,name', 'version', 'runs' => fn ($query) => $query->latest('id')->limit(50)])
                ->latest('id')
                ->get()
                ->map(function (AppInstallation $installation) {
                    $recipe = $installation->version->recipe();

                    return [
                        'uuid' => $installation->uuid,
                        'app' => $installation->recipe->name,
                        'version' => $installation->version->version,
                        'status' => $installation->status,
                        'progress' => $installation->progress,
                        'step' => $installation->step,
                        'error' => $installation->error,
                        'created_at' => $installation->created_at?->toIso8601String(),
                        'finished_at' => $installation->finished_at?->toIso8601String(),
                        // docs/adr/0016 §4: uninstall and actions, and the latest runs (outputs stay with the billing system).
                        'can_uninstall' => $recipe->canUninstall() && $installation->status->canUninstall(),
                        'actions' => $installation->status === AppInstallationStatus::Installed
                            ? array_map(fn (RecipeAction $action) => ['key' => $action->key, 'label' => $action->label, 'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), $action->inputs)], $recipe->actions)
                            : [],
                        'runs' => $installation->runs->take(5)->map(fn (AppRun $run) => [
                            'uuid' => $run->uuid,
                            'kind' => $run->kind,
                            'action' => $run->action === null ? null : ($recipe->action($run->action)->label ?? ['en' => $run->action]),
                            'status' => $run->status,
                            'step' => $run->step,
                            'error' => $run->error,
                            'created_at' => $run->created_at?->toIso8601String(),
                        ])->values(),
                    ];
                }),
            'appOptions' => fn () => AppRecipe::query()->with('latestVersion')->where('is_active', true)->orderBy('name')->get()
                ->filter(fn (AppRecipe $app) => $eligibility->forServer($app, $server) === null)
                ->map(fn (AppRecipe $app) => [
                    'slug' => $app->slug,
                    'name' => $app->name,
                    'inputs' => array_map(fn (RecipeInput $input) => $input->toArray(), $app->recipe()->inputs),
                ])
                ->values(),
        ]);
    }

    public function suspend(Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        return $this->startTask($server, TaskType::Suspend, $admin, [ServerStatus::Active], 'Suspending the server…');
    }

    public function unsuspend(Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        return $this->startTask($server, TaskType::Unsuspend, $admin, [ServerStatus::Suspended], 'Starting the server…');
    }

    /**
     * Run the create pipeline again after a failure; it continues where it stopped.
     */
    public function power(Request $request, Server $server, PowerAction $action, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($server->status !== ServerStatus::Active) {
            $this->toast('error', 'This action is not possible while the server is :status.', ['status' => __(ucfirst($server->status->value))]);

            return back();
        }

        try {
            $this->power->request($server, $action, $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', $action->startedMessage());

        return back();
    }

    public function retry(Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        // Retry whatever left the server in error: a delete, a reinstall or (by default) the creation.
        $type = match ($server->tasks()->latest('id')->first()?->type) {
            TaskType::Terminate => TaskType::Terminate,
            TaskType::Reinstall => TaskType::Reinstall,
            default => TaskType::Create,
        };

        return $this->startTask($server, $type, $admin, [ServerStatus::Error], 'Retrying…');
    }

    public function destroy(Server $server, #[CurrentUser] User $admin): RedirectResponse
    {
        return $this->startTask(
            $server,
            TaskType::Terminate,
            $admin,
            [ServerStatus::Active, ServerStatus::Suspended, ServerStatus::Error],
            'The server is being deleted.',
        );
    }

    public function revealPassword(Request $request, Server $server, #[CurrentUser] User $admin, ServerPasswords $passwords): RedirectResponse
    {
        return back()->with('revealed_password', $passwords->reveal($server, $admin, $request->ip()));
    }

    public function reinstall(ReinstallRequest $request, Server $server, #[CurrentUser] User $admin, ServerReinstaller $reinstaller): RedirectResponse
    {
        try {
            $reinstaller->request($server, $request->osTemplate(), $request->sshKeys(), $admin, $request->ip(), $request->app(), $request->appInputs());
        } catch (AppNotEligible $e) {
            throw ValidationException::withMessages(['app' => $e->translatedMessage()]);
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', 'Reinstalling the server…');

        return back();
    }

    public function changeHostname(HostnameRequest $request, Server $server, #[CurrentUser] User $admin, HostnameChanger $changer): RedirectResponse
    {
        try {
            $changer->request($server, $request->newHostname(), $request->reboot(), $admin, $request->ip());
        } catch (TaskAlreadyRunning $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['hostname' => $e->translatedMessage()]);
        }

        $this->toast('info', 'Changing the hostname…');

        return back();
    }

    public function changePrimaryAddress(PrimaryAddressRequest $request, Server $server, #[CurrentUser] User $admin, PrimaryAddressChanger $changer): RedirectResponse
    {
        try {
            $changer->request($server, $request->ipVersion(), $request->address(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            if ($request->address() !== null && ! $e instanceof TaskAlreadyRunning) {
                throw ValidationException::withMessages(['address' => $e->translatedMessage()]);
            }

            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', 'Changing the primary address…');

        return back();
    }

    public function changeResources(ChangeResourcesRequest $request, Server $server, #[CurrentUser] User $admin, ResourceChanger $changer): RedirectResponse
    {
        try {
            $changer->request($server, $request->toChange(), $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage(), ['status' => __(ucfirst($server->status->value))]);

            return back();
        }

        $this->toast('info', 'Changing the resources…');

        return back();
    }

    public function resetPassword(Request $request, Server $server, #[CurrentUser] User $admin, ServerPasswords $passwords): RedirectResponse
    {
        if ($server->status !== ServerStatus::Active) {
            $this->toast('error', 'This action is not possible while the server is :status.', ['status' => __(ucfirst($server->status->value))]);

            return back();
        }

        try {
            $passwords->requestReset($server, $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', 'Setting a new root password…');

        return back();
    }

    /**
     * @param  list<ServerStatus>  $allowedFrom
     */
    private function startTask(Server $server, TaskType $type, User $admin, array $allowedFrom, string $message): RedirectResponse
    {
        if (! in_array($server->status, $allowedFrom, true)) {
            $this->toast('error', 'This action is not possible while the server is :status.', ['status' => __(ucfirst($server->status->value))]);

            return back();
        }

        try {
            $this->tasks->start($server, $type, $admin);
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->getMessage());

            return back();
        }

        $this->toast('info', $message);

        return back();
    }
}
