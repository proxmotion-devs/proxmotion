<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Domain\Webhooks\Webhooks;
use App\Enums\AppInstallationStatus;
use App\Enums\AppInstallMode;
use App\Enums\AppRunStatus;
use App\Models\AppInstallation;
use App\Models\Server;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finishes installations (docs/adr/0015) and tells the billing system
 * through webhooks (app.installed, app.failed). A webhook problem never
 * changes the installation.
 */
final readonly class AppInstallations
{
    public function __construct(
        private Webhooks $webhooks,
        private AppLogs $logs,
    ) {}

    /**
     * @param  array<string, array{type: string, value: string, filename?: string|null}>  $outputs
     */
    public function installed(AppInstallation $installation, array $outputs, ?string $step = null): void
    {
        $installation->forceFill([
            'status' => AppInstallationStatus::Installed,
            'progress' => 100,
            'step' => Str::limit($step ?? 'Installed', 197),
            'error' => null,
            'outputs' => $outputs,
            'finished_at' => now(),
        ])->save();

        $this->notify($installation);
    }

    public function failed(AppInstallation $installation, string $error, ?string $step = null): void
    {
        $installation->forceFill([
            'status' => AppInstallationStatus::Failed,
            'step' => $step === null ? $installation->step : Str::limit($step, 197),
            'error' => Str::limit($error, 997),
            'finished_at' => now(),
        ])->save();

        $this->notify($installation);
    }

    /**
     * The server's system was replaced or deleted (reinstall, terminate): a
     * running installation fails, the others are gone with the old system.
     *
     * @param  bool  $keepOrdered  keep the app queued for the new system (a reinstall with an app)
     */
    public function serverWiped(Server $server, string $reason, bool $keepOrdered = false): void
    {
        foreach ($server->appInstallations()->with('recipe')->get() as $installation) {
            if ($keepOrdered && $installation->mode === AppInstallMode::Order && $installation->status === AppInstallationStatus::Queued) {
                continue;
            }

            // Uninstalls and actions end with the system they ran on (docs/adr/0016 §4).
            foreach ($installation->runs()->whereIn('status', [AppRunStatus::Queued, AppRunStatus::Running])->get() as $run) {
                $run->forceFill(['status' => AppRunStatus::Failed, 'error' => Str::limit($reason, 997), 'finished_at' => now()])->save();
            }

            if ($installation->status->isBusy()) {
                $this->failed($installation, $reason);
            }

            $this->logs->closeAll($installation);

            if ($installation->status !== AppInstallationStatus::Removed) {
                $installation->forceFill(['status' => AppInstallationStatus::Removed])->save();
            }
        }
    }

    private function notify(AppInstallation $installation): void
    {
        try {
            $this->webhooks->appFinished($installation);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
