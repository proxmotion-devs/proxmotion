<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Firewall\ServerFirewalls;
use App\Domain\Webhooks\Webhooks;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\AppRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finishes uninstalls, upgrades and actions (docs/adr/0016 §4) and tells the
 * billing system (app.uninstalled, app.uninstall_failed, app.upgraded,
 * app.upgrade_failed, app.action_succeeded, app.action_failed). An uninstall
 * that succeeded removes the installation and its outputs; one that failed
 * leaves it uninstall_failed. An upgrade that succeeded moves the
 * installation to the new version; one that failed leaves it upgrade_failed
 * on its old version. Actions never change the installation. A webhook
 * problem never changes the outcome.
 */
final readonly class AppRuns
{
    public function __construct(
        private Webhooks $webhooks,
        private ServerFirewalls $firewalls,
    ) {}

    /**
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    public function succeeded(AppRun $run, array $outputs = [], ?string $step = null): void
    {
        DB::transaction(function () use ($run, $outputs, $step) {
            $upgrade = $run->kind === AppRunKind::Upgrade;
            $run->forceFill([
                'status' => AppRunStatus::Succeeded,
                'progress' => 100,
                'step' => Str::limit($step ?? 'Done', 197),
                'error' => null,
                // An upgrade's outputs belong to the installation, not the run.
                'outputs' => $upgrade ? [] : $outputs,
                'finished_at' => now(),
            ])->save();

            if ($upgrade) {
                $this->upgraded($run, $outputs);
            }

            if ($run->kind === AppRunKind::Uninstall) {
                $run->installation->forceFill([
                    'status' => AppInstallationStatus::Removed,
                    'outputs' => null,
                    'error' => null,
                    'finished_at' => now(),
                ])->save();
            }
        });

        // The app's ports change with it (docs/adr/0035 §4).
        if ($run->kind === AppRunKind::Uninstall || $run->kind === AppRunKind::Upgrade) {
            $this->firewalls->refresh($run->installation->server);
        }

        $this->notify($run);
    }

    public function failed(AppRun $run, string $error, ?string $step = null): void
    {
        DB::transaction(function () use ($run, $error, $step) {
            $run->forceFill([
                'status' => AppRunStatus::Failed,
                'step' => $step === null ? $run->step : Str::limit($step, 197),
                'error' => Str::limit($error, 997),
                'finished_at' => now(),
            ])->save();

            if ($run->kind === AppRunKind::Uninstall && $run->installation->status === AppInstallationStatus::Uninstalling) {
                $run->installation->forceFill([
                    'status' => AppInstallationStatus::UninstallFailed,
                    'error' => Str::limit($error, 997),
                ])->save();
            }

            // The installation keeps its version, inputs and outputs.
            if ($run->kind === AppRunKind::Upgrade && $run->installation->status === AppInstallationStatus::Upgrading) {
                $run->installation->forceFill([
                    'status' => AppInstallationStatus::UpgradeFailed,
                    'error' => Str::limit($error, 997),
                ])->save();
            }
        });

        $this->notify($run);
    }

    /**
     * The installation moves to the version it was upgraded to, with the
     * inputs it was upgraded with. Outputs the upgrade wrote replace those of
     * the same key; outputs the new version no longer declares are dropped.
     *
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    private function upgraded(AppRun $run, array $outputs): void
    {
        $installation = $run->installation;
        $declared = array_map(fn (RecipeOutput $output) => $output->key, $run->declaredOutputs());
        $kept = array_intersect_key($installation->outputs ?? [], array_flip($declared));

        $installation->forceFill([
            'app_recipe_version_id' => $run->runVersion()->id,
            'inputs' => $run->inputs,
            'outputs' => $outputs + $kept,
            'status' => AppInstallationStatus::Installed,
            'progress' => 100,
            'error' => null,
            'finished_at' => now(),
        ])->save();

        $installation->unsetRelation('version');
    }

    private function notify(AppRun $run): void
    {
        try {
            $this->webhooks->appRunFinished($run);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
