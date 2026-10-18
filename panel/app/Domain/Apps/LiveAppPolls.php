<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Enums\AppInstallationStatus;
use App\Enums\AppRunStatus;
use App\Models\AppLog;
use App\Models\AppRun;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Polls running app scripts while an administrator looks at them (docs/adr/0021):
 * the log dialog and the refreshes of the server page run the same poll as
 * apps:poll, so step, progress and log follow within seconds instead of a
 * minute. At most every EVERY_SECONDS per script, whoever asks.
 */
final readonly class LiveAppPolls
{
    public const int EVERY_SECONDS = 2;

    public function __construct(
        private InstallationPoller $installations,
        private RunPoller $runs,
        private AppLogs $logs,
    ) {}

    /**
     * Poll the script the log belongs to, if it runs and was not polled just now.
     */
    public function log(AppLog $log): void
    {
        if (! $log->isLive() || ! Cache::add('app-live-poll:'.$log->id, true, self::EVERY_SECONDS)) {
            return;
        }

        $log->run !== null ? $this->runs->poll($log->run) : $this->installations->poll($log->installation);
    }

    /**
     * Poll the scripts running on the server.
     */
    public function server(Server $server): void
    {
        $installations = $server->appInstallations()
            ->with(['version', 'runs' => fn ($query) => $query->where('status', AppRunStatus::Running)->with('log')])
            ->where(fn ($query) => $query
                ->where('status', AppInstallationStatus::Installing)
                ->orWhereHas('runs', fn ($runs) => $runs->where('status', AppRunStatus::Running)))
            ->get();

        foreach ($installations as $installation) {
            $installation->setRelation('server', $server);
            $logs = $installation->status === AppInstallationStatus::Installing ? [$this->logs->current($installation)] : [];
            $logs = [...$logs, ...$installation->runs->map(fn (AppRun $run) => $run->log?->setRelation('run', $run))->all()];

            foreach (array_filter($logs) as $log) {
                $log->setRelation('installation', $installation);
                $this->log($log);
            }
        }
    }
}
