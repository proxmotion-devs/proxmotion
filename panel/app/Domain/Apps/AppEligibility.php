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
 * an approved version, free (paid apps need entitlements, Phase 5b), the
 * install mode among the recipe's (later on a running server, or with the
 * system a server is ordered or reinstalled with), a template whose
 * platform, family (or family group) and version fit the recipe, enough
 * resources and a supported network model. Ineligible apps are not listed
 * and cannot be installed.
 */
final class AppEligibility
{
    /**
     * Why the app cannot be installed on the server now, or null.
     *
     * @param  AppRecipeVersion|null  $version  the version to check; the latest by default
     */
    public function forServer(AppRecipe $app, Server $server, ?AppRecipeVersion $version = null, AppInstallMode $mode = AppInstallMode::Post): ?AppNotEligible
    {
        return $this->check($app, $version, $mode, $server->osTemplate, $server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->network_model);
    }

    /**
     * Why the app cannot come with a system built from this template and
     * these resources (a new server, or a reinstall), or null.
     */
    public function forOrder(AppRecipe $app, OsTemplate $osTemplate, int $cores, int $memoryMib, int $diskMib, NetworkModel $networkModel): ?AppNotEligible
    {
        return $this->check($app, null, AppInstallMode::Order, $osTemplate, $cores, $memoryMib, $diskMib, $networkModel);
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

    private function check(AppRecipe $app, ?AppRecipeVersion $version, AppInstallMode $mode, ?OsTemplate $osTemplate, int $cores, int $memoryMib, int $diskMib, NetworkModel $networkModel): ?AppNotEligible
    {
        $version ??= $app->latestVersion;

        if ($version === null) {
            return new AppNotEligible('This app is not available.');
        }

        $recipe = $version->recipe();

        return $this->common($app, $recipe, $mode)
            ?? $this->os($recipe, $osTemplate)
            ?? $this->resources($recipe, $cores, $memoryMib, $diskMib)
            ?? ($recipe->supportsNetworkModel($networkModel) ? null : new AppNotEligible('This app does not support the server\'s network model.'));
    }

    private function common(AppRecipe $app, Recipe $recipe, AppInstallMode $mode): ?AppNotEligible
    {
        if (! $app->is_active) {
            return new AppNotEligible('This app is not available.');
        }

        if ($recipe->billing !== 'free') {
            return new AppNotEligible('Paid apps cannot be installed yet.');
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
