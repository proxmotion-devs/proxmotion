<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Enums\ApiScope;
use App\Http\Requests\Api\V1\InstallAppRequest;
use App\Http\Resources\Api\V1\AppInstallationResource;
use App\Http\Resources\Api\V1\AppResource;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
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
     * package or one of the billing system's servers.
     */
    public function index(Request $request, AppEligibility $eligibility): AnonymousResourceCollection
    {
        $token = $this->token($request);
        $request->validate([
            'os_template' => ['nullable', 'uuid', Rule::exists('os_templates', 'uuid')->where('is_active', true)],
            'package' => ['nullable', 'uuid', Rule::exists('packages', 'uuid')->where('is_active', true)],
            'server' => ['nullable', 'uuid', Rule::exists('servers', 'uuid')->where('billing_system_id', $token->billing_system_id)],
        ]);

        $osTemplate = $request->filled('os_template') ? OsTemplate::query()->where('uuid', $request->string('os_template'))->first() : null;
        $package = $request->filled('package') ? Package::query()->where('uuid', $request->string('package'))->first() : null;
        $server = $request->filled('server') ? Server::query()->with('osTemplate')->where('uuid', $request->string('server'))->first() : null;

        $apps = AppRecipe::query()->with('latestVersion')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (AppRecipe $app) => $server !== null
                ? $eligibility->forServer($app, $server) === null
                : $eligibility->forCatalog($app, $osTemplate, $package) === null)
            ->values();

        return AppResource::collection($apps);
    }

    public function installations(Request $request, Server $server): AnonymousResourceCollection
    {
        $installations = $server->appInstallations()->with(['recipe', 'version', 'server', 'task'])->latest('id')->get();
        $outputs = $this->token($request)->hasScope(ApiScope::AppsSecrets);

        return AppInstallationResource::collection($installations->map(fn (AppInstallation $installation) => (new AppInstallationResource($installation))->withOutputs($outputs)));
    }

    public function show(Request $request, Server $server, AppInstallation $installation): JsonResponse
    {
        $this->ensureBelongs($server, $installation);
        $installation->load(['recipe', 'version', 'server', 'task']);

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
     * An installation of another server answers like an unknown one.
     */
    private function ensureBelongs(Server $server, AppInstallation $installation): void
    {
        if ($installation->server_id !== $server->id) {
            throw (new ModelNotFoundException)->setModel(AppInstallation::class, [$installation->uuid]);
        }
    }

    private function installationAccepted(AppInstallation $installation): JsonResponse
    {
        return (new AppInstallationResource($installation->refresh()->load(['recipe', 'version', 'server', 'task'])))->response()->setStatusCode(202);
    }
}
