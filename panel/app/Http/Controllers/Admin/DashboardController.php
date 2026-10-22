<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\NodeStatus;
use App\Http\Controllers\Controller;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Node;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\ServerDrift;
use App\Models\UsageViolation;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
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
        ]);
    }
}
