<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppEntitlementSource;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\NetworkModel;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\TaskRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an app installation and queues the install_app task that starts
 * the installer inside the server (docs/adr/0015). One app installs at a time
 * per server; apps:poll follows the rest.
 */
final readonly class AppInstaller
{
    public function __construct(
        private AppEligibility $eligibility,
        private InputValidator $inputs,
        private TaskRunner $tasks,
        private AppEntitlements $entitlements,
    ) {}

    /**
     * @param  array<array-key, mixed>  $inputs  as given by the billing system or administrator
     *
     * @throws ValidationException for invalid inputs
     * @throws AppNotEligible
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException when the server is not active
     */
    public function install(Server $server, AppRecipe $app, array $inputs, User|ApiToken $actor, ?string $ipAddress): AppInstallation
    {
        $this->ensureActive($server);

        $problem = $this->eligibility->forServer($app, $server);

        if ($problem !== null) {
            throw $problem;
        }

        $values = $this->inputs->validate($app->recipe(), $inputs);
        $this->ensureNothingRunning($server);

        if ($server->appInstallations()->where('app_recipe_id', $app->id)->whereIn('status', [AppInstallationStatus::Installed, AppInstallationStatus::UninstallFailed])->exists()) {
            throw new AppInstallConflict('This app is already installed on the server.');
        }

        $installation = DB::transaction(function () use ($server, $app, $values, $actor) {
            $installation = new AppInstallation;
            $installation->forceFill([
                'server_id' => $server->id,
                'app_recipe_id' => $app->id,
                'app_recipe_version_id' => $app->current()->id,
                'status' => AppInstallationStatus::Queued,
                'inputs' => $values,
                'user_id' => $actor instanceof User ? $actor->id : null,
                'api_token_id' => $actor instanceof ApiToken ? $actor->id : null,
            ])->save();

            return $this->start($installation, $actor);
        });

        AuditLog::record('app.install_requested', $actor, $server, ['app' => $app->slug, 'installation' => $installation->uuid], $ipAddress);

        return $installation;
    }

    /**
     * Check an app chosen with a new system (a new server or a reinstall,
     * docs/adr/0016 §5) before anything is reserved or changed.
     *
     * @param  array<array-key, mixed>  $inputs  as given by the billing system or administrator
     * @param  Server|null  $server  the server being reinstalled; a paid app needs its entitlement (docs/adr/0019)
     * @return array<string, string|int|bool> the validated inputs
     *
     * @throws ValidationException for invalid inputs (under app_inputs.<key>)
     * @throws AppNotEligible
     */
    public function validateOrdered(AppRecipe $app, array $inputs, OsTemplate $osTemplate, int $cores, int $memoryMib, int $diskMib, NetworkModel $networkModel, ?Server $server = null): array
    {
        $problem = $this->eligibility->forOrder($app, $osTemplate, $cores, $memoryMib, $diskMib, $networkModel, $server);

        if ($problem !== null) {
            throw $problem;
        }

        return $this->inputs->validate($app->recipe(), $inputs, 'app_inputs');
    }

    /**
     * Record an app chosen with a new system. The create or reinstall
     * pipeline starts it once the guest agent answers (docs/adr/0004).
     * Call inside the transaction that queues that task.
     *
     * @param  array<string, string|int|bool>  $values  from validateOrdered()
     */
    public function queueOrdered(Server $server, AppRecipe $app, array $values, User|ApiToken|null $actor, ?string $ipAddress): AppInstallation
    {
        $this->dropOrdered($server);

        $installation = new AppInstallation;
        $installation->forceFill([
            'server_id' => $server->id,
            'app_recipe_id' => $app->id,
            'app_recipe_version_id' => $app->current()->id,
            'status' => AppInstallationStatus::Queued,
            'mode' => AppInstallMode::Order,
            'inputs' => $values,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'api_token_id' => $actor instanceof ApiToken ? $actor->id : null,
        ])->save();

        AuditLog::record('app.install_requested', $actor, $server, ['app' => $app->slug, 'installation' => $installation->uuid, 'mode' => AppInstallMode::Order->value], $ipAddress);

        // The order paid for a paid app (docs/adr/0019); a reinstall already had the right.
        if ($app->recipe()->billing === 'paid') {
            $this->entitlements->grant($server, $app, AppEntitlementSource::Order, $actor, null, $ipAddress);
        }

        return $installation;
    }

    /**
     * Forget an app queued for a system that never booted (a failed
     * creation) when the server gets another one.
     */
    public function dropOrdered(Server $server): void
    {
        $server->appInstallations()
            ->where('mode', AppInstallMode::Order)
            ->where('status', AppInstallationStatus::Queued)
            ->update(['status' => AppInstallationStatus::Removed, 'finished_at' => now()]);
    }

    /**
     * Run a failed installation again with the same inputs and recipe version.
     *
     * @throws AppNotEligible
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException
     */
    public function retry(AppInstallation $installation, User|ApiToken $actor, ?string $ipAddress): AppInstallation
    {
        $server = $installation->server;

        if ($installation->status !== AppInstallationStatus::Failed) {
            throw new AppInstallConflict('Only a failed installation can be retried.');
        }

        $this->ensureActive($server);

        $problem = $this->eligibility->forServer($installation->recipe, $server, $installation->version, $installation->mode);

        if ($problem !== null) {
            throw $problem;
        }

        $this->ensureNothingRunning($server);

        $installation = DB::transaction(function () use ($installation, $actor) {
            $installation->forceFill([
                'status' => AppInstallationStatus::Queued,
                'progress' => 0,
                'step' => null,
                'error' => null,
                'finished_at' => null,
            ])->save();

            return $this->start($installation, $actor);
        });

        AuditLog::record('app.install_retried', $actor, $server, ['app' => $installation->recipe->slug, 'installation' => $installation->uuid], $ipAddress);

        return $installation;
    }

    private function start(AppInstallation $installation, User|ApiToken $actor): AppInstallation
    {
        // Refuses while another task runs, which rolls the installation back.
        $task = $this->tasks->start($installation->server, TaskType::InstallApp, $actor, input: ['installation' => $installation->uuid]);
        $installation->forceFill(['task_id' => $task->id])->save();

        return $installation;
    }

    private function ensureActive(Server $server): void
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }
    }

    private function ensureNothingRunning(Server $server): void
    {
        $busy = $server->appInstallations()
            ->whereIn('status', [AppInstallationStatus::Queued, AppInstallationStatus::Installing, AppInstallationStatus::Uninstalling])
            ->exists();

        if ($busy) {
            throw new AppInstallConflict('Another app is still being installed on this server.');
        }
    }
}
