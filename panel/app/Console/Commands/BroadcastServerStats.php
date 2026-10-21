<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Usage\LiveStats;
use App\Events\ServerStatsUpdated;
use App\Models\Server;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servers:stats')]
#[Description('Send the live figures of the servers administrators are watching to their pages')]
class BroadcastServerStats extends Command
{
    public function handle(LiveStats $stats): int
    {
        // Without a WebSocket server nobody could receive them; the pages poll instead (docs/adr/0036 §5).
        if (in_array(config('broadcasting.default'), ['log', 'null', null], true)) {
            $this->components->info('Broadcasting is off; nothing to send.');

            return self::SUCCESS;
        }

        $failed = false;
        $sampled = $stats->sampleWatched(function (Server $server, array $figures) use (&$failed): void {
            if ($failed) {
                return;
            }

            try {
                ServerStatsUpdated::dispatch($server->uuid, $figures);
            } catch (BroadcastException $e) {
                // Reverb is down: stop sending this round, the pages fall back to polling.
                report($e);
                $failed = true;
            }
        });

        $this->components->info("Sampled {$sampled} watched servers.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
