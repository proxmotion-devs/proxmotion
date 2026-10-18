<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PackageRequest;
use App\Models\Cluster;
use App\Models\Package;
use App\Models\PlacementGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/packages/Index', [
            'packages' => Package::query()
                ->with(['clusters:id', 'placementGroups:id'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Package $package) => [
                    'uuid' => $package->uuid,
                    'name' => $package->name,
                    'cpu_cores' => $package->cpu_cores,
                    'memory_mib' => $package->memory_mib,
                    'disk_mib' => $package->disk_mib,
                    'traffic_bytes' => $package->traffic_bytes,
                    'port_speed_mbps' => $package->port_speed_mbps,
                    'ipv4_count' => $package->ipv4_count,
                    'ipv6_count' => $package->ipv6_count,
                    'snapshot_slots' => $package->snapshot_slots,
                    'backup_slots' => $package->backup_slots,
                    'network_models' => $package->network_models,
                    'is_active' => $package->is_active,
                    'sort_order' => $package->sort_order,
                    'cluster_ids' => $package->clusters->pluck('id'),
                    'placement_group_ids' => $package->placementGroups->pluck('id'),
                ]),
            'clusters' => Cluster::query()->with('location:id,name')->orderBy('name')->get(['id', 'name', 'location_id'])
                ->map(fn (Cluster $cluster) => ['id' => $cluster->id, 'name' => $cluster->name, 'location' => $cluster->location->name]),
            'placementGroups' => PlacementGroup::query()->with('location:id,name')->orderBy('name')->get(['id', 'name', 'location_id'])
                ->map(fn (PlacementGroup $group) => ['id' => $group->id, 'name' => $group->name, 'location' => $group->location->name]),
        ]);
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $package = Package::create($request->packageData());
            $package->clusters()->sync($request->clusterIds());
            $package->placementGroups()->sync($request->placementGroupIds());
        });

        $this->toast('success', 'Package created.');

        return to_route('admin.packages.index');
    }

    public function update(PackageRequest $request, Package $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package) {
            $package->update($request->packageData());
            $package->clusters()->sync($request->clusterIds());
            $package->placementGroups()->sync($request->placementGroupIds());
        });

        $this->toast('success', 'Package updated.');

        return to_route('admin.packages.index');
    }

    public function destroy(Package $package): RedirectResponse
    {
        $servers = $package->servers()->count();

        if ($servers > 0) {
            $this->toast('error', ':count servers use this package (deleted ones included). Deactivate it instead.', ['count' => (string) $servers]);

            return back();
        }

        $package->delete();

        $this->toast('success', 'Package deleted.');

        return to_route('admin.packages.index');
    }
}
