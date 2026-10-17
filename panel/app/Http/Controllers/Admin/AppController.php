<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Apps\AppInstallConflict;
use App\Domain\Apps\AppInstaller;
use App\Domain\Apps\AppRunner;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRecipeVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
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
        return Inertia::render('admin/apps/Index', [
            'apps' => AppRecipe::query()
                ->with(['latestVersion', 'versions' => fn ($query) => $query->where('status', AppRecipeVersionStatus::Draft)])
                ->withCount(['installations as installed_count' => fn ($query) => $query->where('status', AppInstallationStatus::Installed)])
                ->orderBy('name')
                ->get()
                ->map(function (AppRecipe $app) {
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
                        'published' => $app->latestVersion !== null,
                        'draft_version' => $draft?->version,
                        'version' => $app->latestVersion?->version,
                        'category' => $app->category,
                        'description' => $recipe->description,
                        'os' => $recipe->osSummary(),
                        'billing' => $recipe->billing,
                        'is_active' => $app->is_active,
                        'installed_count' => (int) $app->getAttribute('installed_count'),
                    ];
                })
                ->filter()
                ->values(),
        ]);
    }

    public function update(Request $request, AppRecipe $appRecipe, #[CurrentUser] User $admin): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $appRecipe->forceFill(['is_active' => (bool) $data['is_active']])->save();
        AuditLog::record($appRecipe->is_active ? 'app.activated' : 'app.deactivated', $admin, $appRecipe, ['app' => $appRecipe->slug], $request->ip());

        $this->toast('success', $appRecipe->is_active ? 'App activated.' : 'App deactivated.');

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
