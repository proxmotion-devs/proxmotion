<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Webhooks\Webhooks;
use App\Enums\AppInstallationStatus;
use App\Enums\AppRunKind;
use App\Enums\AppRunStatus;
use App\Models\AppRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finishes uninstalls and actions (docs/adr/0016 §4) and tells the billing
 * system (app.uninstalled, app.uninstall_failed, app.action_succeeded,
 * app.action_failed). An uninstall that succeeded removes the installation
 * and its outputs; one that failed leaves it uninstall_failed. Actions never
 * change the installation. A webhook problem never changes the outcome.
 */
final readonly class AppRuns
{
    public function __construct(private Webhooks $webhooks) {}

    /**
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    public function succeeded(AppRun $run, array $outputs = [], ?string $step = null): void
    {
        DB::transaction(function () use ($run, $outputs, $step) {
            $run->forceFill([
                'status' => AppRunStatus::Succeeded,
                'progress' => 100,
                'step' => Str::limit($step ?? 'Done', 197),
                'error' => null,
                'outputs' => $outputs,
                'finished_at' => now(),
            ])->save();

            if ($run->kind === AppRunKind::Uninstall) {
                $run->installation->forceFill([
                    'status' => AppInstallationStatus::Removed,
                    'outputs' => null,
                    'error' => null,
                    'finished_at' => now(),
                ])->save();
            }
        });

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
        });

        $this->notify($run);
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
