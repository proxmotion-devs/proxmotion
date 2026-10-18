<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppNotEntitled;
use App\Domain\Apps\AppRunner;
use App\Enums\ApiScope;
use App\Enums\AppInstallMode;
use App\Http\Requests\Api\V1\InstallAppRequest;
use App\Http\Resources\Api\V1\AppInstallationResource;
use App\Http\Resources\Api\V1\AppResource;
use App\Http\Resources\Api\V1\AppRunResource;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * App Marketplace (docs/adr/0015): the apps a billing system can offer, and
 * installing them on servers. Installs answer 202 with the installation;
 * clients poll it (or wait for the app.installed / app.failed webhook).
 */
class AppController extends ApiController
{
    /**
     * Active apps, optionally only those eligible for an OS template, a
     * package or one of the billing system's servers. "mode=order" lists the
     * apps that can come with a new or reinstalled system (docs/adr/0016 §5)
     * instead of those installed later.
     */
    public function index(Request $request, AppEligibility $eligibility): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $request->validate([
            'os_template' => ['nullable', 'uuid', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            'package' => ['nullable', 'uuid', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'server' => ['nullable', 'uuid', Rule::exists('servers', 'uuid')->where('billing_system_id', $token->billing_system_id)],
            'mode' => ['nullable', Rule::enum(AppInstallMode::class)],
        ]);

        $osTemplate = $request->filled('os_template') ? OsTemplate::query()->where('uuid', $request->string('os_template'))->first() : null;
        $package = $request->filled('package') ? Package::query()->where('uuid', $request->string('package'))->first() : null;
        $server = $request->filled('server') ? Server::query()->with('osTemplate')->where('uuid', $request->string('server'))->first() : null;
        $mode = $request->enum('mode', AppInstallMode::class) ?? AppInstallMode::Post;

        $apps = AppRecipe::query()->with('latestVersion')->where('is_active', true)->orderBy('name')->get();

        if ($server === null) {
            return AppResource::collection($apps->filter(fn (AppRecipe $app) => $eligibility->forCatalog($app, $osTemplate, $package, $mode) === null)->values());
        }

        // Paid apps the server has no right to yet are listed as apps to buy (docs/adr/0019).
        $problems = $apps->mapWithKeys(fn (AppRecipe $app) => [$app->id => $eligibility->forServer($app, $server, mode: $mode)]);

        return AppResource::collection($apps
            ->filter(fn (AppRecipe $app) => $problems[$app->id] === null || $problems[$app->id] instanceof AppNotEntitled)
            ->values()
            ->map(fn (AppRecipe $app) => (new AppResource($app))->withEntitlement($problems[$app->id] === null)));
    }

    public function installations(Request $request, Server $server): AnonymousResourceCollection
    {
        // Trials of drafts (docs/adr/0018 §7) are the administrators' own.
        $installations = $server->appInstallations()->where('is_trial', false)->with(['recipe.latestVersion', 'version', 'server.osTemplate', 'task'])->latest('id')->get();
        $outputs = $this->token($request)->hasScope(ApiScope::AppsSecrets);

        return AppInstallationResource::collection($installations->map(fn (AppInstallation $installation) => (new AppInstallationResource($installation))->withOutputs($outputs)));
    }

    public function show(Request $request, Server $server, AppInstallation $installation): JsonResponse
    {
        $this->ensureBelongs($server, $installation);
        $installation->load(['recipe.latestVersion', 'version', 'server.osTemplate', 'task']);

        return (new AppInstallationResource($installation))
            ->withOutputs($this->token($request)->hasScope(ApiScope::AppsSecrets))
            ->response();
    }

    public function store(InstallAppRequest $request, Server $server, AppInstaller $installer): JsonResponse
    {
        try {
            $installation = $installer->install($server, $request->app(), $request->inputs(), $this->token($request), $request->ip());
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }

        return $this->installationAccepted($installation);
    }

    public function retry(Request $request, Server $server, AppInstallation $installation, AppInstaller $installer): JsonResponse
    {
        $this->ensureBelongs($server, $installation);

        try {
            $installation = $installer->retry($installation, $this->token($request), $request->ip());
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }

        return $this->installationAccepted($installation);
    }

    /**
     * Uninstall (docs/adr/0016 §4): 202 with the run; the installation is
     * removed when it succeeds (app.uninstalled / app.uninstall_failed).
     */
    public function uninstall(Request $request, Server $server, AppInstallation $installation, AppRunner $runner): JsonResponse
    {
        $this->ensureBelongs($server, $installation);

        return $this->runResponse(fn () => $runner->uninstall($installation, $this->token($request), $request->ip()));
    }

    /**
     * Upgrade to the version the installation's `upgrade` names, with values
     * for the inputs it adds (docs/adr/0016 §4): 202 with the run; the
     * installation moves to the new version when it succeeds (app.upgraded /
     * app.upgrade_failed).
     */
    public function upgrade(Request $request, Server $server, AppInstallation $installation, AppRunner $runner): JsonResponse
    {
        $this->ensureBelongs($server, $installation);
        $request->validate(['inputs' => ['nullable', 'array']]);
        $inputs = $request->input('inputs');

        return $this->runResponse(fn () => $runner->upgrade($installation, is_array($inputs) ? $inputs : [], $this->token($request), $request->ip()));
    }

    /**
     * Run an action of an installed app with its inputs: 202 with the run.
     */
    public function action(Request $request, Server $server, AppInstallation $installation, string $action, AppRunner $runner): JsonResponse
    {
        $this->ensureBelongs($server, $installation);
        $request->validate(['inputs' => ['nullable', 'array']]);
        $inputs = $request->input('inputs');

        return $this->runResponse(fn () => $runner->action($installation, $action, is_array($inputs) ? $inputs : [], $this->token($request), $request->ip()));
    }

    /**
     * The installation's latest uninstalls and actions (at most 10), newest
     * first; outputs only with the apps:secrets scope.
     */
    public function runs(Request $request, Server $server, AppInstallation $installation): AnonymousResourceCollection
    {
        $this->ensureBelongs($server, $installation);
        $outputs = $this->token($request)->hasScope(ApiScope::AppsSecrets);
        $runs = $installation->runs()->with(['installation.version', 'targetVersion', 'task'])->latest('id')->limit(10)->get();

        return AppRunResource::collection($runs->map(fn (AppRun $run) => (new AppRunResource($run))->withOutputs($outputs)));
    }

    /**
     * An uninstall or action with its status; outputs only with the apps:secrets scope.
     */
    public function run(Request $request, Server $server, AppInstallation $installation, AppRun $run): JsonResponse
    {
        $this->ensureBelongs($server, $installation);

        if ($run->app_installation_id !== $installation->id) {
            throw (new ModelNotFoundException)->setModel(AppRun::class, [$run->uuid]);
        }

        return (new AppRunResource($run->load(['installation.version', 'targetVersion', 'task'])))
            ->withOutputs($this->token($request)->hasScope(ApiScope::AppsSecrets))
            ->response();
    }

    /**
     * @param  callable(): AppRun  $start
     */
    private function runResponse(callable $start): JsonResponse
    {
        try {
            $run = $start();
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            return $this->conflict($e->translatedMessage());
        } catch (ProvisioningException $e) {
            return response()->json(['message' => $e->translatedMessage()], 422);
        }

        return (new AppRunResource($run->refresh()->load(['installation.version', 'targetVersion', 'task'])))->response()->setStatusCode(202);
    }

    /**
     * An installation of another server, or a trial (docs/adr/0018 §7),
     * answers like an unknown one.
     */
    private function ensureBelongs(Server $server, AppInstallation $installation): void
    {
        if ($installation->server_id !== $server->id || $installation->is_trial) {
            throw (new ModelNotFoundException)->setModel(AppInstallation::class, [$installation->uuid]);
        }
    }

    private function installationAccepted(AppInstallation $installation): JsonResponse
    {
        return (new AppInstallationResource($installation->refresh()->load(['recipe.latestVersion', 'version', 'server.osTemplate', 'task'])))->response()->setStatusCode(202);
    }
}
