<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Enums\ServerStatus;
use App\Enums\TaskType;
use App\Models\ApiToken;
use App\Models\AppInstallation;
use App\Models\AppRecipeVersion;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\TaskRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts uninstalls, upgrades and actions of installed apps (docs/adr/0016
 * §4): records an AppRun and queues the short app_run task that starts the
 * script inside the server with the installation's recipe version (an
 * upgrade: the version it upgrades to); apps:poll follows it. One run at a
 * time per installation, and no uninstall or upgrade while another app
 * installs, uninstalls or upgrades on the server.
 */
final readonly class AppRunner
{
    public function __construct(
        private InputValidator $inputs,
        private TaskRunner $tasks,
        private AppUpgrades $upgrades,
    ) {}

    /**
     * @throws AppNotEligible when the recipe has no uninstall script
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException when the server is not active
     */
    public function uninstall(AppInstallation $installation, User|ApiToken $actor, ?string $ipAddress): AppRun
    {
        $server = $installation->server;
        $this->ensureActive($server);

        if (! $installation->version->recipe()->canUninstall()) {
            throw new AppNotEligible('This app cannot be uninstalled.');
        }

        if (! $installation->status->canUninstall()) {
            throw new AppInstallConflict('This app cannot be uninstalled while it is :status.', ['status' => $installation->status->value]);
        }

        $this->ensureIdle($installation);
        $this->ensureNoOtherBusy($installation);

        $run = DB::transaction(function () use ($installation, $actor) {
            $run = $this->record($installation, AppRunKind::Uninstall, null, [], $actor);
            $installation->forceFill(['status' => AppInstallationStatus::Uninstalling, 'error' => null])->save();

            return $this->start($run, $actor);
        });

        AuditLog::record('app.uninstall_requested', $actor, $server, ['app' => $installation->recipe->slug, 'installation' => $installation->uuid, 'run' => $run->uuid], $ipAddress);

        return $run;
    }

    /**
     * Upgrade to the app's published version with that version's upgrade
     * script (docs/adr/0016 §4). The installation keeps its version until the
     * script succeeded. Inputs the new version added are given here (those
     * with a default may be left out); the installed values are kept.
     *
     * @param  array<array-key, mixed>  $inputs  values of the new version's new inputs
     *
     * @throws ValidationException for invalid inputs
     * @throws AppNotEligible when there is no upgrade for the installation
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException when the server is not active
     */
    public function upgrade(AppInstallation $installation, array $inputs, User|ApiToken $actor, ?string $ipAddress): AppRun
    {
        $server = $installation->server;
        $this->ensureActive($server);

        if (! $installation->status->canUpgrade()) {
            throw new AppInstallConflict('This app cannot be upgraded while it is :status.', ['status' => $installation->status->value]);
        }

        $target = $this->upgrades->target($installation);

        if ($target === null) {
            throw new AppNotEligible('No upgrade is available for this app.');
        }

        $added = $this->inputs->validateInputs(AppUpgrades::newInputs($installation, $target), $inputs);
        // The installed values of inputs the new version still declares, checked against its types.
        $kept = array_intersect_key($installation->inputs, array_flip(array_map(fn (RecipeInput $input) => $input->key, $target->recipe()->inputs)));
        $values = $this->inputs->validateInputs($target->recipe()->inputs, $kept + $added);

        $this->ensureIdle($installation);
        $this->ensureNoOtherBusy($installation);

        $run = DB::transaction(function () use ($installation, $target, $values, $actor) {
            $run = $this->record($installation, AppRunKind::Upgrade, null, $values, $actor, $target);
            $installation->forceFill(['status' => AppInstallationStatus::Upgrading, 'error' => null])->save();

            return $this->start($run, $actor);
        });

        AuditLog::record('app.upgrade_requested', $actor, $server, [
            'app' => $installation->recipe->slug,
            'installation' => $installation->uuid,
            'from' => $installation->version->version,
            'to' => $target->version,
            'run' => $run->uuid,
        ], $ipAddress);

        return $run;
    }

    /**
     * @param  array<array-key, mixed>  $inputs  as given by the billing system or administrator
     *
     * @throws ValidationException for invalid inputs
     * @throws AppNotEligible for an unknown action
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException when the server is not active
     */
    public function action(AppInstallation $installation, string $key, array $inputs, User|ApiToken $actor, ?string $ipAddress): AppRun
    {
        $server = $installation->server;
        $this->ensureActive($server);

        $action = $installation->version->recipe()->action($key);

        if ($action === null) {
            throw new AppNotEligible('This app has no action :action.', ['action' => $key]);
        }

        if ($installation->status !== AppInstallationStatus::Installed) {
            throw new AppInstallConflict('Actions need an installed app.');
        }

        $values = $this->inputs->validateInputs($action->inputs, $inputs);
        $this->ensureIdle($installation);

        $run = DB::transaction(fn () => $this->start($this->record($installation, AppRunKind::Action, $key, $values, $actor), $actor));

        AuditLog::record('app.action_requested', $actor, $server, ['app' => $installation->recipe->slug, 'installation' => $installation->uuid, 'action' => $key, 'run' => $run->uuid], $ipAddress);

        return $run;
    }

    /**
     * @param  array<string, string|int|bool>  $inputs
     */
    private function record(AppInstallation $installation, AppRunKind $kind, ?string $action, array $inputs, User|ApiToken $actor, ?AppRecipeVersion $target = null): AppRun
    {
        $run = new AppRun;
        $run->forceFill([
            'app_installation_id' => $installation->id,
            'kind' => $kind,
            'action' => $action,
            'target_version_id' => $target?->id,
            'status' => AppRunStatus::Queued,
            'inputs' => $inputs,
            'user_id' => $actor instanceof User ? $actor->id : null,
            'api_token_id' => $actor instanceof ApiToken ? $actor->id : null,
        ])->save();

        return $run;
    }

    private function start(AppRun $run, User|ApiToken $actor): AppRun
    {
        // Refuses while another task runs, which rolls the run back.
        $task = $this->tasks->start($run->installation->server, TaskType::AppRun, $actor, input: ['run' => $run->uuid]);
        $run->forceFill(['task_id' => $task->id])->save();

        return $run;
    }

    private function ensureActive(Server $server): void
    {
        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }
    }

    private function ensureIdle(AppInstallation $installation): void
    {
        if ($installation->runs()->whereIn('status', [AppRunStatus::Queued, AppRunStatus::Running])->exists()) {
            throw new AppInstallConflict('Another action is still running for this app.');
        }
    }

    private function ensureNoOtherBusy(AppInstallation $installation): void
    {
        if ($installation->server->appInstallations()->whereKeyNot($installation->id)->whereIn('status', AppInstallationStatus::busy())->exists()) {
            throw new AppInstallConflict('Another app is still being installed on this server.');
        }
    }
}
