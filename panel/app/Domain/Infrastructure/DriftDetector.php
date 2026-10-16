<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure;

use App\Enums\DriftType;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Models\Cluster;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Proxmox\Data\VmResource;

/**
 * Compares a cluster's servers with the guests the sync just read from
 * Proxmox and records the differences (ADR 0005). It makes no API calls and
 * never repairs anything: admins decide.
 */
final readonly class DriftDetector
{
    private const MIB = 1024 * 1024;

    /**
     * @param  list<VmResource>  $guests  every guest of the cluster, from /cluster/resources
     */
    public function detect(Cluster $cluster, array $guests): void
    {
        $vms = [];

        foreach ($guests as $guest) {
            if ($guest->type === 'qemu' && ! $guest->template) {
                $vms[$guest->vmid] = $guest;
            }
        }

        $nodeNames = $cluster->nodes()->pluck('name', 'id');
        $findings = [];
        $skipped = [];

        $servers = Server::query()
            ->where('cluster_id', $cluster->id)
            ->whereIn('status', [ServerStatus::Active, ServerStatus::Suspended])
            ->get();

        foreach ($servers as $server) {
            // A running task changes the VM on purpose; look again next time.
            if ($server->tasks()->whereIn('status', [TaskStatus::Queued, TaskStatus::Running])->exists()) {
                $skipped[] = $server->id;

                continue;
            }

            $vm = $vms[$server->vmid] ?? null;
            $node = (string) ($nodeNames[$server->node_id] ?? '');

            if ($vm === null) {
                $findings[] = [DriftType::MissingVm, $server->vmid, $server->id, ['hostname' => $server->hostname, 'node' => $node], null];

                continue;
            }

            if ($vm->node !== $node) {
                $findings[] = [DriftType::NodeMismatch, $server->vmid, $server->id, ['node' => $node], ['node' => $vm->node]];
            }

            $expected = ['cpu_cores' => $server->cpu_cores, 'memory_mib' => $server->memory_mib, 'disk_mib' => $server->disk_mib];
            // New cores and memory that wait for a restart are not drift (ChangeResourcesPipeline).
            $actual = [
                'cpu_cores' => $server->reboot_required ? $server->cpu_cores : ($vm->cpus ?? $server->cpu_cores),
                'memory_mib' => $server->reboot_required || $vm->memoryBytes === null ? $server->memory_mib : intdiv($vm->memoryBytes, self::MIB),
                'disk_mib' => $vm->diskBytes !== null ? intdiv($vm->diskBytes, self::MIB) : $server->disk_mib,
            ];

            if ($expected !== $actual) {
                $findings[] = [DriftType::ResourceMismatch, $server->vmid, $server->id, $expected, $actual];
            }

            $shouldRun = $server->status === ServerStatus::Active;

            if ($vm->status !== null && ($vm->status === 'running') !== $shouldRun) {
                $findings[] = [DriftType::PowerMismatch, $server->vmid, $server->id, ['status' => $shouldRun ? 'running' : 'stopped'], ['status' => $vm->status]];
            }
        }

        if ($cluster->resource_pool !== null) {
            $known = Server::query()->where('cluster_id', $cluster->id)->where('status', '!=', ServerStatus::Terminated)->pluck('vmid')->all();

            foreach ($vms as $vm) {
                if ($vm->pool === $cluster->resource_pool && ! in_array($vm->vmid, $known, true)) {
                    $findings[] = [DriftType::UnmanagedVm, $vm->vmid, null, null, ['name' => $vm->name, 'node' => $vm->node, 'status' => $vm->status]];
                }
            }
        }

        $this->record($cluster, $findings, $skipped);
    }

    /**
     * @param  list<array{DriftType, int, int|null, array<string, mixed>|null, array<string, mixed>|null}>  $findings
     * @param  list<int>  $skippedServerIds
     */
    private function record(Cluster $cluster, array $findings, array $skippedServerIds): void
    {
        $open = ServerDrift::query()
            ->where('cluster_id', $cluster->id)
            ->whereNull('resolved_at')
            ->get()
            ->keyBy(fn (ServerDrift $drift) => "{$drift->type->value}:{$drift->vmid}");
        $now = now();
        $seen = [];

        foreach ($findings as [$type, $vmid, $serverId, $expected, $actual]) {
            $key = "{$type->value}:{$vmid}";
            $seen[$key] = true;
            $drift = $open->get($key) ?? new ServerDrift;

            $drift->forceFill([
                'cluster_id' => $cluster->id,
                'server_id' => $serverId,
                'vmid' => $vmid,
                'type' => $type,
                'expected' => $expected,
                'actual' => $actual,
                'first_seen_at' => $drift->exists ? $drift->first_seen_at : $now,
                'last_seen_at' => $now,
            ])->save();
        }

        foreach ($open as $key => $drift) {
            if (! isset($seen[$key]) && ! in_array($drift->server_id, $skippedServerIds, true)) {
                $drift->resolve('gone');
            }
        }
    }
}
