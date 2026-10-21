<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Enums\ServerStatus;
use App\Models\Server;
use App\Proxmox\Data\RrdPoint;
use App\Proxmox\Data\VmStats;
use App\Proxmox\Exceptions\ProxmoxAuthenticationFailed;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\Exceptions\ProxmoxUnreachable;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Live figures of the servers administrators are looking at (docs/adr/0036): who is
 * watching, one sample per server from Proxmox, and rates from two samples' counters.
 *
 * @phpstan-type Stats array{time: int, status: string, uptime: int|null, cpu_percent: float|null, cpus: int|null, memory_bytes: int|null, memory_total_bytes: int|null, net_in: float|null, net_out: float|null, disk_read: float|null, disk_write: float|null}
 * @phpstan-type HistoryPoint array{time: int, cpu_percent: float|null, memory_bytes: int|null, net_in: float|null, net_out: float|null, disk_read: float|null, disk_write: float|null}
 */
final class LiveStats
{
    /** A page that stops asking drops out after this long. */
    public const int WATCH_SECONDS = 75;

    /** A sample younger than this is answered without asking Proxmox again. */
    public const int FRESH_SECONDS = 15;

    /** The previous sample of a server, for its rates. */
    private const int SAMPLE_SECONDS = 300;

    private const string WATCHED_KEY = 'server-stats:watched';

    public function __construct(private readonly ProxmoxClientFactory $clients) {}

    /**
     * Whether the server has a VM whose figures can be asked for.
     */
    public static function available(Server $server): bool
    {
        return in_array($server->status, [ServerStatus::Active, ServerStatus::Suspended], true);
    }

    /**
     * Mark the server as watched for WATCH_SECONDS.
     */
    public function watch(Server $server): void
    {
        try {
            Cache::lock(self::WATCHED_KEY.':lock', 5)->block(2, function () use ($server): void {
                $now = now()->getTimestamp();
                $watched = array_filter($this->watchedUntil(), fn (int $until) => $until > $now);
                $watched[$server->id] = $now + self::WATCH_SECONDS;
                Cache::put(self::WATCHED_KEY, $watched, self::WATCH_SECONDS);
            });
        } catch (LockTimeoutException) {
            // The page asks again in 30 seconds.
        }
    }

    /**
     * @return Collection<int, Server>
     */
    public function watched(): Collection
    {
        $now = now()->getTimestamp();
        $ids = array_keys(array_filter($this->watchedUntil(), fn (int $until) => $until > $now));

        if ($ids === []) {
            return new Collection;
        }

        // The cluster's connection needs all its columns.
        return Server::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [ServerStatus::Active, ServerStatus::Suspended])
            ->with(['cluster', 'node:id,name'])
            ->get();
    }

    /**
     * Ask Proxmox once for every watched server and hand each one's figures to $each.
     * A cluster that cannot be reached is skipped for this round.
     *
     * @param  callable(Server, Stats): void  $each
     * @return int the servers sampled
     */
    public function sampleWatched(callable $each): int
    {
        $sampled = 0;

        foreach ($this->watched()->groupBy('cluster_id') as $servers) {
            /** @var Server $first */
            $first = $servers->first();
            $client = $this->clients->forCluster($first->cluster);

            foreach ($servers as $server) {
                try {
                    $stats = $this->record($server, $client->qemu($server->node->name)->stats($server->vmid));
                } catch (ProxmoxUnreachable|ProxmoxAuthenticationFailed) {
                    continue 2;
                } catch (ProxmoxException) {
                    continue;
                }

                $each($server, $stats);
                $sampled++;
            }
        }

        return $sampled;
    }

    /**
     * The latest figures: a fresh sample from the cache, otherwise asked from Proxmox;
     * null when the server has no VM to ask or Proxmox does not answer.
     *
     * @return Stats|null
     */
    public function current(Server $server): ?array
    {
        if (! self::available($server)) {
            return null;
        }

        $last = $this->lastSample($server);

        if ($last !== null && now()->getTimestampMs() - $last['time'] < self::FRESH_SECONDS * 1000) {
            return $last['stats'];
        }

        try {
            return $this->record($server, $this->clients->forCluster($server->cluster()->firstOrFail())->qemu($server->node->name)->stats($server->vmid));
        } catch (ProxmoxException) {
            return null;
        }
    }

    /**
     * Averages per minute of the last hour, oldest first; empty when Proxmox does not answer.
     *
     * @return list<HistoryPoint>
     */
    public function history(Server $server): array
    {
        if (! self::available($server)) {
            return [];
        }

        try {
            $points = $this->clients->forCluster($server->cluster()->firstOrFail())->qemu($server->node->name)->rrd($server->vmid);
        } catch (ProxmoxException) {
            return [];
        }

        return array_map(fn (RrdPoint $point) => [
            'time' => $point->time * 1000,
            'cpu_percent' => $point->cpu === null ? null : round($point->cpu * 100, 1),
            'memory_bytes' => $point->memoryBytes === null ? null : (int) $point->memoryBytes,
            'net_in' => $point->netIn,
            'net_out' => $point->netOut,
            'disk_read' => $point->diskRead,
            'disk_write' => $point->diskWrite,
        ], $points);
    }

    /**
     * Turn a sample into figures, with rates against the previous sample, and keep it for the next one.
     *
     * @return Stats
     */
    private function record(Server $server, VmStats $sample): array
    {
        $time = now()->getTimestampMs();
        $last = $this->lastSample($server);
        $counters = $sample->counters();
        $rates = array_fill_keys(array_keys($counters), null);

        // No rate across a restart: the counters start from zero again (and never a negative one).
        $comparable = $last !== null
            && $time > $last['time']
            && $last['status'] === $sample->status
            && ($sample->uptime === null || $last['uptime'] === null || $sample->uptime >= $last['uptime']);

        if ($comparable) {
            $seconds = ($time - $last['time']) / 1000;

            foreach ($counters as $key => $value) {
                $before = $last['counters'][$key];
                $rates[$key] = $value !== null && $before !== null && $value >= $before ? round(($value - $before) / $seconds, 1) : null;
            }
        }

        $stats = [
            'time' => $time,
            'status' => $sample->status,
            'uptime' => $sample->uptime,
            'cpu_percent' => $sample->cpu === null ? null : round($sample->cpu * 100, 1),
            'cpus' => $sample->cpus,
            'memory_bytes' => $sample->memoryBytes,
            'memory_total_bytes' => $sample->memoryTotalBytes,
            ...$rates,
        ];

        Cache::put($this->sampleKey($server), [
            'time' => $time,
            'status' => $sample->status,
            'uptime' => $sample->uptime,
            'counters' => $counters,
            'stats' => $stats,
        ], self::SAMPLE_SECONDS);

        return $stats;
    }

    /**
     * @return array{time: int, status: string, uptime: int|null, counters: array{net_in: int|null, net_out: int|null, disk_read: int|null, disk_write: int|null}, stats: Stats}|null
     */
    private function lastSample(Server $server): ?array
    {
        $last = Cache::get($this->sampleKey($server));

        /** @var array{time: int, status: string, uptime: int|null, counters: array{net_in: int|null, net_out: int|null, disk_read: int|null, disk_write: int|null}, stats: Stats}|null */
        return is_array($last) ? $last : null;
    }

    /**
     * @return array<int, int> server id => watched until (Unix time)
     */
    private function watchedUntil(): array
    {
        $watched = Cache::get(self::WATCHED_KEY);

        /** @var array<int, int> */
        return is_array($watched) ? $watched : [];
    }

    private function sampleKey(Server $server): string
    {
        return "server-stats:sample:{$server->id}";
    }
}
