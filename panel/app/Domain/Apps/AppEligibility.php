<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallMode;
use App\Enums\NetworkModel;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;

/**
 * Whether an app may be installed (docs/adr/0015, 0016 §3, §5): active with
 * an approved version, the install mode among the recipe's (later on a
 * running server, or with the system a server is ordered or reinstalled
 * with), a template whose platform, family (or family group) and version
 * fit the recipe, enough resources and a supported network model.
 * Ineligible apps are not listed and cannot be installed.
 *
 * Paid apps (docs/adr/0019) also need an active entitlement on the server,
 * except when a new server is ordered with them (the order grants one).
 * That is checked last and reported as AppNotEntitled, so the catalog can
 * still list them as apps to buy.
 */
final readonly class AppEligibility
{
    public function __construct(private AppEntitlements $entitlements) {}

    /**
     * Why the app cannot be installed on the server now, or null.
     *
     * @param  AppRecipeVersion|null  $version  the version to check; the latest by default
     */
    public function forServer(AppRecipe $app, Server $server, ?AppRecipeVersion $version = null, AppInstallMode $mode = AppInstallMode::Post): ?AppNotEligible
    {
        return $this->check($app, $version, $mode, $server->osTemplate, $server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->network_model)
            ?? $this->entitlement($app, $version, $server);
    }

    /**
     * Why a trial version cannot be tried on the test server, or null
     * (docs/adr/0018 §7): the same checks, but an inactive app may be tried
     * (it is activated once it works) and a paid one needs no entitlement.
     */
    public function forTrial(AppRecipe $app, AppRecipeVersion $version, Server $server): ?AppNotEligible
    {
        return $this->check($app, $version, AppInstallMode::Post, $server->osTemplate, $server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->network_model, requireActive: false);
    }

    /**
     * Why the app cannot come with a system built from this template and
     * these resources, or null: a new server, or a reinstall of $server.
     */
    public function forOrder(AppRecipe $app, OsTemplate $osTemplate, int $cores, int $memoryMib, int $diskMib, NetworkModel $networkModel, ?Server $server = null): ?AppNotEligible
    {
        return $this->check($app, null, AppInstallMode::Order, $osTemplate, $cores, $memoryMib, $diskMib, $networkModel)
            ?? ($server === null ? null : $this->entitlement($app, null, $server));
    }

    /**
     * Why an installed app cannot be upgraded to this version on its server,
     * or null (docs/adr/0016 §4): active, and the version fits the server's
     * operating system, resources and network model. Install modes and
     * entitlements were settled when the app was installed.
     */
    public function forUpgrade(AppRecipe $app, AppRecipeVersion $version, Server $server): ?AppNotEligible
    {
        $recipe = $version->recipe();

        if (! $app->is_active || $recipe->platform !== 'linux') {
            return new AppNotEligible('This app is not available.');
        }

        return $this->os($recipe, $server->osTemplate)
            ?? $this->resources($recipe, $server->cpu_cores, $server->memory_mib, $server->disk_mib)
            ?? ($recipe->supportsNetworkModel($server->network_model) ? null : new AppNotEligible('This app does not support the server\'s network model.'));
    }

    private function entitlement(AppRecipe $app, ?AppRecipeVersion $version, Server $server): ?AppNotEntitled
    {
        $recipe = ($version ?? $app->latestVersion)?->recipe();

        if ($recipe === null || $recipe->billing !== 'paid' || $this->entitlements->has($server, $app)) {
            return null;
        }

        return new AppNotEntitled('This app has to be bought for the server first.');
    }

    /**
     * Why the app is not offered for this OS template and package (either may
     * be left out), or null.
     */
    public function forCatalog(AppRecipe $app, ?OsTemplate $osTemplate, ?Package $package, AppInstallMode $mode = AppInstallMode::Post): ?AppNotEligible
    {
        if ($app->latestVersion === null) {
            return new AppNotEligible('This app is not available.');
        }

        $recipe = $app->latestVersion->recipe();
        $problem = $this->common($app, $recipe, $mode)
            ?? ($osTemplate === null ? null : $this->os($recipe, $osTemplate))
            ?? ($package === null ? null : $this->resources($recipe, $package->cpu_cores, $package->memory_mib, $package->disk_mib));

        if ($problem !== null || $package === null) {
            return $problem;
        }

        foreach ($package->network_models as $model) {
            if ($recipe->supportsNetworkModel($model)) {
                return null;
            }
        }

        return new AppNotEligible('This app does not support the package\'s network models.');
    }

    private function check(AppRecipe $app, ?AppRecipeVersion $version, AppInstallMode $mode, ?OsTemplate $osTemplate, int $cores, int $memoryMib, int $diskMib, NetworkModel $networkModel, bool $requireActive = true): ?AppNotEligible
    {
        $version ??= $app->latestVersion;

        if ($version === null) {
            return new AppNotEligible('This app is not available.');
        }

        $recipe = $version->recipe();

        return $this->common($app, $recipe, $mode, $requireActive)
            ?? $this->os($recipe, $osTemplate)
            ?? $this->resources($recipe, $cores, $memoryMib, $diskMib)
            ?? ($recipe->supportsNetworkModel($networkModel) ? null : new AppNotEligible('This app does not support the server\'s network model.'));
    }

    private function common(AppRecipe $app, Recipe $recipe, AppInstallMode $mode, bool $requireActive = true): ?AppNotEligible
    {
        if ($requireActive && ! $app->is_active) {
            return new AppNotEligible('This app is not available.');
        }

        if (! $recipe->allowsInstallMode($mode)) {
            return new AppNotEligible($mode === AppInstallMode::Post
                ? 'This app can only be installed when the server is ordered.'
                : 'This app can only be installed on a running server.');
        }

        // Windows recipes need the PowerShell runner (docs/adr/0016 §3), not built yet.
        if ($recipe->platform !== 'linux') {
            return new AppNotEligible('This app does not support the operating system.');
        }

        return null;
    }

    private function os(Recipe $recipe, ?OsTemplate $template): ?AppNotEligible
    {
        if ($template === null || ! $recipe->supportsTemplate($template)) {
            return new AppNotEligible('This app does not support the operating system.');
        }

        return null;
    }

    private function resources(Recipe $recipe, int $cores, int $memoryMib, int $diskMib): ?AppNotEligible
    {
        if ($cores < $recipe->minCores) {
            return new AppNotEligible('This app needs at least :count vCPU cores.', ['count' => (string) $recipe->minCores]);
        }

        if ($memoryMib < $recipe->minMemoryMib) {
            return new AppNotEligible('This app needs at least :size MiB of memory.', ['size' => (string) $recipe->minMemoryMib]);
        }

        if ($diskMib < $recipe->minDiskMib) {
            return new AppNotEligible('This app needs at least :size MiB of disk space.', ['size' => (string) $recipe->minDiskMib]);
        }

        return null;
    }
}
