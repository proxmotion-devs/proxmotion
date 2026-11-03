<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Backups\ServerSnapshots;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRecipeVersionStatus;
use App\Enums\ServerStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Snapshot;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Support\Ssh\PublicKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trial runs of an app's draft on test servers (docs/adr/0018 §7). A trial
 * installs a frozen copy of the draft (status trial, numbered
 * <draft version>-trial.N), so saving the draft later never changes what a
 * trial installation runs; its uninstall and actions keep using the copy.
 * Trial installations are never shown to billing systems: test servers have
 * none, and the API and webhooks leave trials out as well.
 */
final readonly class AppTrials
{
    /** At most this many trial installations are listed in the editor. */
    public const LISTED = 10;

    public function __construct(
        private RecipeEditor $editor,
        private RecipeLoader $loader,
        private AppInstaller $installer,
        private AppEligibility $eligibility,
        private ServerReinstaller $reinstaller,
        private ServerSnapshots $snapshots,
    ) {}

    /** The label of the snapshot a test server is reset to, when it has one (docs/adr/0051 §3). */
    public const string TRIAL_BASE = 'trial base';

    /**
     * The test server's newest snapshot labelled TRIAL_BASE (any case), or null.
     */
    public static function trialBase(Server $server): ?Snapshot
    {
        return $server->snapshots()->latest('id')->get()
            ->first(fn (Snapshot $snapshot) => mb_strtolower(trim((string) $snapshot->label)) === self::TRIAL_BASE);
    }

    /**
     * Try the app's draft as it is saved now on a test server.
     *
     * @param  array<array-key, mixed>  $inputs  as given by the administrator
     *
     * @throws RecipeEditRefused when there is no draft or it does not validate
     * @throws ValidationException for invalid inputs
     * @throws AppNotEligible
     * @throws AppInstallConflict
     * @throws TaskAlreadyRunning
     * @throws ProvisioningException when the server is not an active test server
     */
    public function try(AppRecipe $app, Server $server, array $inputs, User $admin, ?string $ipAddress): AppInstallation
    {
        $draft = $app->draft() ?? throw new RecipeEditRefused('This app has no draft.');

        if ($this->editor->errors($draft) !== []) {
            throw new RecipeEditRefused('Fix the errors of the draft before trying it.');
        }

        // A refused install leaves no new trial version behind.
        return DB::transaction(fn () => $this->installer->installTrial($server, $this->freeze($draft), $inputs, $admin, $ipAddress));
    }

    /**
     * Mark a server as a test server, or not any more. A server of a billing
     * system cannot be one: its customer would see what is tried on it.
     *
     * @throws ProvisioningException
     */
    public function markTestServer(Server $server, bool $test, User $admin, ?string $ipAddress): void
    {
        if ($test && $server->billing_system_id !== null) {
            throw new ProvisioningException('A server of a billing system cannot be a test server.');
        }

        if ($test && in_array($server->status, [ServerStatus::Terminating, ServerStatus::Terminated], true)) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        if ($server->is_test === $test) {
            return;
        }

        $server->forceFill(['is_test' => $test])->save();
        AuditLog::record($test ? 'server.test_marked' : 'server.test_unmarked', $admin, $server, ['hostname' => $server->hostname], $ipAddress);
    }

    /**
     * Clean a test server after trials: roll it back to its snapshot
     * labelled "trial base" when it has one (docs/adr/0051 §3), otherwise
     * reinstall it from its current OS template with the same SSH keys (a
     * new root password). Either removes every app installed since.
     *
     * @throws ProvisioningException when it is not an active test server, its template is gone, or a task runs
     */
    public function resetTestServer(Server $server, User $admin, ?string $ipAddress): Task
    {
        if (! $server->is_test) {
            throw new ProvisioningException('Only a test server can be reset after a trial.');
        }

        if ($server->status !== ServerStatus::Active) {
            throw new ProvisioningException('This action is not possible while the server is :status.', ['status' => $server->status->value]);
        }

        $base = self::trialBase($server);

        if ($base !== null) {
            $task = $this->snapshots->rollback($server, $base, $admin, $ipAddress);
            AuditLog::record('server.trial_reset', $admin, $server, ['hostname' => $server->hostname, 'snapshot' => $base->label], $ipAddress);

            return $task;
        }

        $template = $server->osTemplate;

        if ($template === null || ! $this->reinstaller->eligibleTemplates($server)->contains('id', $template->id)) {
            throw new ProvisioningException('The server\'s operating system template is no longer available; reinstall it with another one.');
        }

        $task = $this->reinstaller->request($server, $template, PublicKey::parseMany($server->ssh_keys ?? ''), $admin, $ipAddress);
        AuditLog::record('server.trial_reset', $admin, $server, ['hostname' => $server->hostname, 'os_template' => $template->name], $ipAddress);

        return $task;
    }

    /**
     * The frozen copy of the draft's current content: the latest one when
     * the draft has not changed since, otherwise a new trial version.
     *
     * @throws RecipeInvalid
     */
    public function freeze(AppRecipeVersion $draft): AppRecipeVersion
    {
        $content = self::content($draft);
        $trials = AppRecipeVersion::query()->where('trial_of_id', $draft->id)->latest('id')->get();
        $same = $trials->first(fn (AppRecipeVersion $trial) => self::content($trial) === $content);

        if ($same !== null) {
            return $same;
        }

        $app = $draft->app;
        // recipe.yaml keeps the draft's version (the format allows no -trial.N); only the stored row is numbered.
        ['recipe' => $recipe] = $this->loader->validate($app->slug, $draft->definition, $draft->files);

        $trial = (new AppRecipeVersion)->forceFill([
            'app_recipe_id' => $app->id,
            'version' => $draft->version.'-trial.'.($this->highestTrial($app, $draft->version) + 1),
            'format' => $recipe->format,
            'definition' => $draft->definition,
            // All of the draft's files, so the copy matches the draft exactly.
            'files' => $draft->files,
            'checksum' => RecipeSynchronizer::checksum($recipe, $draft->files),
            'status' => AppRecipeVersionStatus::Trial,
            'origin' => AppRecipeVersion::ORIGIN_LOCAL,
            'trial_of_id' => $draft->id,
        ]);
        $trial->save();

        return $trial->setRelation('app', $app);
    }

    /**
     * The latest trial installations of the app for the editor, each saying
     * whether it installed the draft as it is saved now.
     *
     * @return list<array<string, mixed>>
     */
    public function forEditor(AppRecipe $app, ?AppRecipeVersion $draft): array
    {
        $content = $draft === null ? null : self::content($draft);

        return array_values($app->installations()
            ->where('is_trial', true)
            ->with(['server:id,uuid,hostname', 'version'])
            ->latest('id')
            ->limit(self::LISTED)
            ->get()
            ->map(fn (AppInstallation $installation) => [
                'uuid' => $installation->uuid,
                'server' => ['uuid' => $installation->server->uuid, 'hostname' => $installation->server->hostname],
                'version' => $installation->version->version,
                'status' => $installation->status->value,
                'progress' => $installation->progress,
                'step' => $installation->step,
                'error' => $installation->error,
                // The draft has not changed since this trial.
                'current' => $draft !== null && $installation->version->trial_of_id === $draft->id && self::content($installation->version) === $content,
                'created_at' => $installation->created_at?->toIso8601String(),
                'finished_at' => $installation->finished_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Active test servers the draft could be tried on, with the reason when
     * it does not fit one.
     *
     * @return list<array{uuid: string, hostname: string, problem: string|null}>
     */
    public function testServers(AppRecipe $app, ?AppRecipeVersion $draft): array
    {
        // Only a draft without errors can be checked against the servers.
        $checked = $draft !== null && $this->editor->errors($draft) === [] ? $draft : null;

        /** @var Collection<int, Server> $servers */
        $servers = Server::query()
            ->where('is_test', true)
            ->where('status', ServerStatus::Active)
            ->with('osTemplate')
            ->orderBy('hostname')
            ->get();

        return array_values($servers->map(function (Server $server) use ($app, $checked) {
            $problem = $checked === null ? null : $this->eligibility->forTrial($app, $checked, $server);

            return [
                'uuid' => $server->uuid,
                'hostname' => $server->hostname,
                'problem' => $problem?->translatedMessage(),
            ];
        })->all());
    }

    /**
     * Poll the servers where a trial of the app runs, for the editor's refreshes.
     */
    public function pollRunning(AppRecipe $app, LiveAppPolls $live): void
    {
        $servers = Server::query()
            ->whereHas('appInstallations', fn ($query) => $query
                ->where('app_recipe_id', $app->id)
                ->where('is_trial', true)
                ->whereIn('status', AppInstallationStatus::busy()))
            ->get();

        foreach ($servers as $server) {
            $live->server($server);
        }
    }

    private function highestTrial(AppRecipe $app, string $base): int
    {
        $highest = 0;

        foreach ($app->versions()->where('status', AppRecipeVersionStatus::Trial)->pluck('version') as $version) {
            if (preg_match('/^'.preg_quote($base, '/').'-trial\.(\d+)$/', (string) $version, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest;
    }

    /**
     * What a draft or trial version installs: recipe.yaml without its
     * version, and the files.
     */
    private static function content(AppRecipeVersion $version): string
    {
        $definition = $version->definition;
        unset($definition['version']);
        $files = $version->files;
        ksort($files);

        return hash('sha256', json_encode([$definition, $files], JSON_THROW_ON_ERROR));
    }
}
