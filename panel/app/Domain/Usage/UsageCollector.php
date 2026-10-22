<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Enums\ServerStatus;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageSample;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collects what the servers of a cluster use (docs/adr/0039): one
 * GET /cluster/resources per cluster, one sample per server, and the
 * traffic since the previous collection added to the server's period.
 *
 * Proxmox's network and disk figures are counters since the VM process
 * started. A counter that went down, or a shorter uptime, means a restart:
 * the new value is counted from zero.
 */
final readonly class UsageCollector
{
    /** How often usage:collect runs; the usage limits count samples by it. */
    public const int INTERVAL_MINUTES = 5;

    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * @return Collection<int, Server> the servers sampled
     *
     * @throws ProxmoxException when the cluster does not answer
     */
    public function collect(Cluster $cluster): Collection
    {
        $servers = Server::query()
            ->where('cluster_id', $cluster->id)
            ->whereIn('status', [ServerStatus::Active, ServerStatus::Suspended])
            ->get();

        if ($servers->isEmpty()) {
            return $servers;
        }

        $guests = collect($this->clients->forCluster($cluster)->cluster()->guests())
            ->filter(fn (VmResource $guest) => $guest->type === 'qemu' && ! $guest->template)
            ->keyBy('vmid');

        $now = now();
        $period = TrafficPeriods::start($now)->toDateString();
        $samples = [];
        $sampled = new Collection;

        foreach ($servers as $server) {
            $guest = $guests->get($server->vmid);

            if (! $guest instanceof VmResource) {
                continue;
            }

            [$sample, $traffic, $counters] = $this->measure($server, $guest, $now->getTimestamp());
            $samples[] = ['server_id' => $server->id, 'sampled_at' => $now, ...$sample];

            DB::transaction(function () use ($server, $traffic, $counters, $period): void {
                if ($traffic['in'] > 0 || $traffic['out'] > 0) {
                    TrafficPeriod::query()->insertOrIgnore([
                        'server_id' => $server->id,
                        'period_start' => $period,
                        'in_bytes' => 0,
                        'out_bytes' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // Added in the database: two collections never overwrite each other's bytes.
                    TrafficPeriod::query()->where('server_id', $server->id)->whereDate('period_start', $period)->toBase()->incrementEach(
                        ['in_bytes' => $traffic['in'], 'out_bytes' => $traffic['out']],
                        ['updated_at' => now()],
                    );
                }

                // Without touching updated_at: this is not a change of the server.
                Server::query()->whereKey($server->id)->update(['usage_counters' => json_encode($counters, JSON_THROW_ON_ERROR)]);
            });

            $server->usage_counters = $counters;
            $sampled->push($server);
        }

        foreach (array_chunk($samples, 200) as $chunk) {
            UsageSample::query()->insert($chunk);
        }

        return $sampled;
    }

    /**
     * The sample's figures, the bytes since the previous collection and the counters to keep.
     *
     * @return array{0: array{status: string, cpu_percent: float|null, memory_percent: float|null, net_in_rate: float|null, net_out_rate: float|null, disk_read_rate: float|null, disk_write_rate: float|null}, 1: array{in: int, out: int}, 2: array{time: int, uptime: int|null, net_in: int|null, net_out: int|null, disk_read: int|null, disk_write: int|null}}
     */
    private function measure(Server $server, VmResource $guest, int $time): array
    {
        $running = $guest->status === 'running';
        $counters = [
            'time' => $time,
            'uptime' => $guest->uptime,
            'net_in' => $guest->netIn,
            'net_out' => $guest->netOut,
            'disk_read' => $guest->diskRead,
            'disk_write' => $guest->diskWrite,
        ];

        $previous = $server->usage_counters;
        $seconds = $previous === null ? 0 : $time - (int) $previous['time'];
        $restarted = $previous !== null && $guest->uptime !== null && $previous['uptime'] !== null && $guest->uptime < $previous['uptime'];

        // Bytes since the previous collection; nothing the first time (what came before is unknown).
        $delta = function (string $key) use ($counters, $previous, $restarted): ?int {
            $now = $counters[$key];

            if ($previous === null || $now === null) {
                return null;
            }

            $before = $previous[$key];

            return $restarted || $before === null || $now < $before ? $now : $now - $before;
        };

        $rate = fn (?int $bytes): ?float => $bytes === null || $seconds <= 0 ? null : round($bytes / $seconds, 1);
        $netIn = $delta('net_in');
        $netOut = $delta('net_out');

        return [
            [
                'status' => $guest->status ?? 'unknown',
                // A stopped VM uses nothing, whatever the last figures were.
                'cpu_percent' => ! $running ? 0.0 : ($guest->cpu === null ? null : round($guest->cpu * 100, 1)),
                'memory_percent' => ! $running ? 0.0 : ($guest->memoryUsedBytes === null || ! $guest->memoryBytes ? null : round($guest->memoryUsedBytes / $guest->memoryBytes * 100, 1)),
                'net_in_rate' => $rate($netIn),
                'net_out_rate' => $rate($netOut),
                'disk_read_rate' => $rate($delta('disk_read')),
                'disk_write_rate' => $rate($delta('disk_write')),
            ],
            ['in' => $netIn ?? 0, 'out' => $netOut ?? 0],
            $counters,
        ];
    }
}
