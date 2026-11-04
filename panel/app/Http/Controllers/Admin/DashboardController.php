<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Installation\SetupProgress;
use App\Enums\NodeStatus;
use App\Enums\ServerStatus;
use App\Enums\StorageHealth;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Node;
use App\Models\NodeDisk;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\Storage;
use App\Models\Task;
use App\Models\UsageViolation;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(SetupProgress $setup): Response
    {
        return Inertia::render('admin/Dashboard', [
            // The setup order's steps, shown until every one is done.
            'setup' => $setup->steps(),
            'stats' => [
                'locations' => Location::query()->count(),
                'clusters' => Cluster::query()->count(),
                'clusters_failing' => Cluster::query()->whereNotNull('last_sync_error')->count(),
                'nodes' => Node::query()->count(),
                'nodes_online' => Node::query()->where('status', NodeStatus::Online)->count(),
                'ip_addresses' => IpAddress::query()->count(),
                'ip_addresses_reserved' => IpAddress::query()->where('is_reserved', true)->count(),
                'os_templates' => OsTemplate::query()->where('is_active', true)->count(),
                'packages' => Package::query()->where('is_active', true)->count(),
                'drifts' => ServerDrift::query()->visible()->count(),
                'storage_problems' => $this->storageProblems(),
            ],
            // Servers over their traffic quota or a usage limit right now (docs/adr/0039).
            'usageViolations' => UsageViolation::query()->open()
                ->with('server:id,uuid,hostname')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (UsageViolation $violation) => [
                    ...$violation->toPayload(),
                    'label' => $violation->kind->label(),
                    'server' => ['uuid' => $violation->server->uuid, 'hostname' => $violation->server->hostname],
                ]),
            'usageViolationCount' => UsageViolation::query()->open()->count(),
            'servers' => $this->serverCounts(),
            // Tasks that failed in the last 24 hours, newest first.
            'failedTasks' => Task::query()
                ->where('status', TaskStatus::Failed)
                ->where('finished_at', '>=', now()->subDay())
                ->with('server:id,uuid,hostname')
                ->latest('finished_at')
                ->limit(5)
                ->get(['id', 'uuid', 'server_id', 'type', 'error', 'finished_at'])
                ->map(fn (Task $task) => [
                    'uuid' => $task->uuid,
                    'type' => $task->type,
                    'error' => $task->error === null ? null : Str::limit($task->error, 160),
                    'finished_at' => $task->finished_at?->toIso8601String(),
                    'server' => ['uuid' => $task->server->uuid, 'hostname' => $task->server->hostname],
                ]),
            'failedTaskCount' => Task::query()
                ->where('status', TaskStatus::Failed)
                ->where('finished_at', '>=', now()->subDay())
                ->count(),
        ]);
    }

    /**
     * Servers that still occupy capacity: active, suspended, busy (being created,
     * reinstalled or deleted) and in error.
     *
     * @return array{total: int, active: int, suspended: int, busy: int, error: int}
     */
    private function serverCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Server::query()->occupying()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $busy = array_filter(ServerStatus::cases(), fn (ServerStatus $status) => $status->isBusy());

        return [
            'total' => array_sum($counts),
            'active' => $counts[ServerStatus::Active->value] ?? 0,
            'suspended' => $counts[ServerStatus::Suspended->value] ?? 0,
            'busy' => array_sum(array_map(fn (ServerStatus $status) => $counts[$status->value] ?? 0, $busy)),
            'error' => $counts[ServerStatus::Error->value] ?? 0,
        ];
    }

    /**
     * Storages and disks of online nodes in warning or critical health (docs/adr/0047);
     * a shared storage, which has a row per node, counts once per cluster.
     */
    private function storageProblems(): int
    {
        $unhealthy = [StorageHealth::Warning, StorageHealth::Critical];
        $online = fn ($query) => $query->where('status', NodeStatus::Online);

        $storages = Storage::query()
            ->whereIn('health', $unhealthy)
            ->whereHas('node', $online)
            ->with('node:id,cluster_id')
            ->get(['id', 'node_id', 'name', 'is_shared'])
            ->unique(fn (Storage $storage) => $storage->is_shared ? "{$storage->node->cluster_id}:{$storage->name}" : "id:{$storage->id}")
            ->count();

        return $storages + NodeDisk::query()->whereIn('health', $unhealthy)->whereHas('node', $online)->count();
    }
}
