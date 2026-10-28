<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppCanceller;
use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppRunner;
use App\Domain\Apps\AppSystems;
use App\Domain\Apps\AppTrials;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRecipeVersionStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppInstallationResource;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\OperatingSystemVersion;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * App Marketplace in the admin panel (docs/adr/0015): the synced recipes
 * (activate or deactivate), and installing apps on servers.
 */
class AppController extends Controller
{
    public function index(): Response
    {
        $versions = AppSystems::all();

        return Inertia::render('admin/apps/Index', [
            // The operating system versions apps can be ticked for (docs/adr/0026).
            'osVersions' => AppSystems::options($versions),
            'apps' => AppRecipe::query()
                ->with(['latestVersion', 'osVersions:id', 'versions' => fn ($query) => $query->where('status', AppRecipeVersionStatus::Draft)])
                ->withCount(['installations as installed_count' => fn ($query) => $query->where('status', AppInstallationStatus::Installed)->where('is_trial', false)])
                // AppRecipe::inUse(): an app with such installations cannot be removed.
                ->withExists(['installations as in_use' => fn ($query) => $query->where(fn ($query) => $query->where('is_trial', false)->orWhere('status', '!=', AppInstallationStatus::Removed))])
                ->orderBy('name')
                ->get()
                ->map(function (AppRecipe $app) use ($versions) {
                    // An app that was never published shows its draft (docs/adr/0018).
                    $draft = $app->versions->first();
                    $recipe = ($app->latestVersion ?? $draft)?->recipe();

                    if ($recipe === null) {
                        return null;
                    }

                    return [
                        'slug' => $app->slug,
                        'name' => $app->name,
                        'source' => $app->source,
                        'modified' => $app->isModified(),
                        'published' => $app->latestVersion !== null,
                        'draft_version' => $draft?->version,
                        'version' => $app->latestVersion?->version,
                        'category' => $app->category,
                        'description' => $recipe->description,
                        'platform' => $recipe->platform,
                        // What the recipe says it runs on, and the versions that matches.
                        'recipe_os' => $recipe->osSummary(),
                        'suggested_ids' => AppSystems::suggested($app, $versions),
                        'os_version_ids' => $app->osVersions->pluck('id')->values(),
                        'billing' => $recipe->billing,
                        'is_active' => $app->is_active,
                        'installed_count' => (int) $app->getAttribute('installed_count'),
                        'in_use' => (bool) $app->getAttribute('in_use'),
                    ];
                })
                ->filter()
                ->values(),
        ]);
    }

    /**
     * The operating system versions the app is offered on (docs/adr/0026).
     */
    public function systems(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate([
            'os_version_ids' => ['present', 'array'],
            'os_version_ids.*' => ['integer', 'distinct', Rule::exists('operating_system_versions', 'id')],
        ]);

        $ids = array_values(array_map(intval(...), $data['os_version_ids']));
        $platform = ($appRecipe->latestVersion ?? $appRecipe->versions()->latest('id')->first())?->recipe()->platform;

        // A Linux app is never offered on Windows, and the other way round.
        if (OperatingSystemVersion::query()->whereKey($ids)->whereHas('operatingSystem', fn ($query) => $query->where('platform', '!=', $platform))->exists()) {
            throw ValidationException::withMessages(['os_version_ids' => __('The app does not run on that kind of system.')]);
        }

        AppSystems::set($appRecipe, $ids, $admin, $request->ip());

        $this->toast('success', 'Operating systems of the app saved.');

        return back();
    }

    public function update(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $appRecipe->forceFill(['is_active' => (bool) $data['is_active']])->save();
        AuditLog::record($appRecipe->is_active ? 'app.activated' : 'app.deactivated', $admin, $appRecipe, ['app' => $appRecipe->slug], $request->ip());

        $this->toast('success', $appRecipe->is_active ? 'App activated.' : 'App deactivated.');

        return back();
    }

    /**
     * Mark a server as a test server for trial runs of drafts (docs/adr/0018 §7), or not any more.
     */
    public function testServer(Request $request, Server $server, #[CurrentUser] User $admin, AppTrials $trials): RedirectResponse
    {
        $data = $request->validate(['is_test' => ['required', 'boolean']]);

        try {
            $trials->markTestServer($server, (bool) $data['is_test'], $admin, $request->ip());
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['is_test' => $e->translatedMessage()]);
        }

        $this->toast('success', $server->is_test ? 'Marked as a test server.' : 'No longer a test server.');

        return back();
    }

    /**
     * Reinstall a test server from its current template after trials, removing every app on it.
     */
    public function resetTestServer(Request $request, Server $server, #[CurrentUser] User $admin, AppTrials $trials): RedirectResponse
    {
        try {
            $task = $trials->resetTestServer($server, $admin, $request->ip());
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        }

        $this->toast('info', $task->type === TaskType::RollbackSnapshot ? 'Rolling back to the "trial base" snapshot…' : 'Reinstalling the server…');

        return back();
    }

    public function install(Request $request, Server $server, #[CurrentUser] User $admin, AppInstaller $installer): RedirectResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', Rule::exists('app_recipes', 'slug')],
            'inputs' => ['nullable', 'array'],
        ]);

        try {
            $installer->install($server, AppRecipe::query()->where('slug', $data['app'])->firstOrFail(), $data['inputs'] ?? [], $admin, $request->ip());
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['app' => $e->translatedMessage()]);
        }

        $this->toast('info', 'Installing the app…');

        return back();
    }

    /**
     * Run the app's uninstall script (docs/adr/0016 §4).
     */
    public function uninstall(Request $request, AppInstallation $appInstallation, #[CurrentUser] User $admin, AppRunner $runner): RedirectResponse
    {
        try {
            $runner->uninstall($appInstallation, $admin, $request->ip());
            $this->toast('info', 'Uninstalling the app…');
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());
        }

        return back();
    }

    /**
     * Upgrade to the app's published version with the inputs it adds (docs/adr/0016 §4).
     */
    public function upgrade(Request $request, AppInstallation $appInstallation, #[CurrentUser] User $admin, AppRunner $runner): RedirectResponse
    {
        $data = $request->validate(['inputs' => ['nullable', 'array']]);

        try {
            $runner->upgrade($appInstallation, $data['inputs'] ?? [], $admin, $request->ip());
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['upgrade' => $e->translatedMessage()]);
        }

        $this->toast('info', 'Upgrading the app…');

        return back();
    }

    /**
     * Run one of the app's actions with its inputs (docs/adr/0016 §4).
     */
    public function action(Request $request, AppInstallation $appInstallation, string $action, #[CurrentUser] User $admin, AppRunner $runner): RedirectResponse
    {
        $data = $request->validate(['inputs' => ['nullable', 'array']]);

        try {
            $runner->action($appInstallation, $action, $data['inputs'] ?? [], $admin, $request->ip());
        } catch (TaskAlreadyRunning|AppInstallConflict $e) {
            $this->toast('error', $e->translatedMessage());

            return back();
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['action' => $e->translatedMessage()]);
        }

        $this->toast('info', 'The action is running…');

        return back();
    }

    /**
     * Show what an installed app reported (panel address, passwords, VPN
     * client files): its own outputs and those of its latest actions. Shown
     * once on the server page, like the root password, and audited.
     */
    public function outputs(Request $request, AppInstallation $appInstallation, #[CurrentUser] User $admin): RedirectResponse
    {
        $appInstallation->load(['version', 'runs' => fn ($query) => $query->latest('id')->limit(5)]);
        $recipe = $appInstallation->version->recipe();
        $installed = in_array($appInstallation->status, [AppInstallationStatus::Installed, AppInstallationStatus::UpgradeFailed], true);

        $runs = $appInstallation->runs
            ->filter(fn (AppRun $run) => $run->kind === AppRunKind::Action && $run->status === AppRunStatus::Succeeded)
            ->map(fn (AppRun $run) => [
                'uuid' => $run->uuid,
                'action' => $recipe->action((string) $run->action)->label ?? ['en' => (string) $run->action],
                'created_at' => $run->created_at?->toIso8601String(),
                'outputs' => AppInstallationResource::outputsOf($run->declaredOutputs(), $run->outputs ?? []),
            ])
            ->filter(fn (array $run) => $run['outputs'] !== [])
            ->values()
            ->all();

        AuditLog::record('app.outputs_revealed', $admin, $appInstallation, ['app' => $appInstallation->recipe->slug, 'server' => $appInstallation->server->hostname], $request->ip());

        return back()->with('revealed_app_outputs', [
            'installation' => $appInstallation->uuid,
            'outputs' => $installed ? AppInstallationResource::outputsOf($recipe->outputs, $appInstallation->outputs ?? []) : [],
            'runs' => $runs,
        ]);
    }

    /**
     * Stop a running installer (docs/adr/0030): the installation fails at once.
     */
    public function cancel(Request $request, AppInstallation $appInstallation, #[CurrentUser] User $admin, AppCanceller $canceller): RedirectResponse
    {
        try {
            $canceller->cancelInstallation($appInstallation, $admin, $request->ip());
            $this->toast('info', 'The installation was cancelled. Its installer is being stopped inside the server.');
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());
        }

        return back();
    }

    /**
     * Stop a running uninstall, upgrade or action (docs/adr/0030).
     */
    public function cancelRun(Request $request, AppRun $appRun, #[CurrentUser] User $admin, AppCanceller $canceller): RedirectResponse
    {
        try {
            $canceller->cancelRun($appRun, $admin, $request->ip());
            $this->toast('info', 'The script was cancelled and is being stopped inside the server.');
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());
        }

        return back();
    }

    public function retry(Request $request, AppInstallation $appInstallation, #[CurrentUser] User $admin, AppInstaller $installer): RedirectResponse
    {
        try {
            $installer->retry($appInstallation, $admin, $request->ip());
            $this->toast('info', 'Installing the app…');
        } catch (ProvisioningException $e) {
            $this->toast('error', $e->translatedMessage());
        }

        return back();
    }
}
