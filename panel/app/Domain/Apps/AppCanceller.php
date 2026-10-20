<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Jobs\StopAppScript;
use App\Models\ApiToken;
use App\Models\AppInstallation;
use App\Models\AppRun;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Cancels a running installer or run (docs/adr/0030 §4): it fails at once
 * with the usual webhooks, and StopAppScript stops it inside the server.
 * Under the pollers' lock, so a poll that just found it finished wins.
 * Nothing the script did is undone; retry, uninstall or a reinstall clean up.
 */
final readonly class AppCanceller
{
    private const int LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private AppInstallations $installations,
        private AppRuns $runs,
        private AppLogs $logs,
    ) {}

    /**
     * @throws AppInstallConflict when it is not installing
     */
    public function cancelInstallation(AppInstallation $installation, User|ApiToken $actor, ?string $ipAddress): AppInstallation
    {
        $this->locked('app-installation-poll:'.$installation->id, function () use ($installation, $actor) {
            $installation->refresh();

            if ($installation->status !== AppInstallationStatus::Installing) {
                throw new AppInstallConflict('Only a running installation can be cancelled.');
            }

            $this->logs->finish($this->logs->current($installation));
            $this->installations->failed($installation, self::message($actor));
        });

        AuditLog::record('app.install_cancelled', $actor, $installation->server, ['app' => $installation->recipe->slug, 'installation' => $installation->uuid], $ipAddress);
        StopAppScript::dispatch($installation->id, AppGuest::installName($installation->uuid, $installation->attempts));

        return $installation;
    }

    /**
     * An uninstall leaves the installation uninstall_failed, an upgrade
     * upgrade_failed on its old version (AppRuns::failed()).
     *
     * @throws AppInstallConflict when it is not running
     */
    public function cancelRun(AppRun $run, User|ApiToken $actor, ?string $ipAddress): AppRun
    {
        $this->locked('app-run-poll:'.$run->id, function () use ($run, $actor) {
            $run->refresh();

            if ($run->status !== AppRunStatus::Running) {
                throw new AppInstallConflict('Only a running script can be cancelled.');
            }

            $this->logs->finish($run->log);
            $this->runs->failed($run, self::message($actor));
        });

        $installation = $run->installation;
        AuditLog::record('app.run_cancelled', $actor, $installation->server, [
            'app' => $installation->recipe->slug,
            'installation' => $installation->uuid,
            'run' => $run->uuid,
            'kind' => $run->kind->value,
        ], $ipAddress);
        StopAppScript::dispatch($installation->id, AppGuest::runName($run->uuid));

        return $run;
    }

    private static function message(User|ApiToken $actor): string
    {
        return $actor instanceof User ? __('Cancelled by an administrator.') : __('Cancelled by the billing system.');
    }

    /**
     * @param  callable(): void  $cancel
     *
     * @throws AppInstallConflict
     */
    private function locked(string $lock, callable $cancel): void
    {
        try {
            Cache::lock($lock, 120)->block(self::LOCK_WAIT_SECONDS, $cancel);
        } catch (LockTimeoutException) {
            throw new AppInstallConflict('The script\'s progress is being read right now. Try again in a moment.');
        }
    }
}
