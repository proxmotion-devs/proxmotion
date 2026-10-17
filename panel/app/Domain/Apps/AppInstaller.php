<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
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

        $problem = $this->eligibility->forServer($installation->recipe, $server, $installation->version);

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
