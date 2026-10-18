<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlacementGroupRequest;
use App\Models\Location;
use App\Models\Node;
use App\Models\PlacementGroup;
use App\Models\Storage;
use App\Provisioning\Placement\NodePlacer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placement groups (docs/adr/0020): named sets of nodes and storages that
 * packages and new servers target. Panel only, nothing changes on Proxmox.
 */
class PlacementGroupController extends Controller
{
    public function index(NodePlacer $placer): Response
    {
        return Inertia::render('admin/placement-groups/Index', [
            'groups' => PlacementGroup::query()
                ->with(['location:id,uuid,name', 'nodes:id,name', 'storages:id,name,node_id'])
                ->withCount('packages')
                ->orderBy('name')
                ->get()
                ->map(fn (PlacementGroup $group) => [
                    'uuid' => $group->uuid,
                    'name' => $group->name,
                    'description' => $group->description,
                    'location' => ['uuid' => $group->location->uuid, 'name' => $group->location->name],
                    'node_ids' => $group->nodes->pluck('id'),
                    'storage_ids' => $group->storages->pluck('id'),
                    'packages_count' => $group->getAttribute('packages_count'),
                ]),
            'locations' => Location::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Location $location) => ['value' => $location->uuid, 'label' => $location->name]),
            'nodes' => fn () => Node::query()
                ->with(['cluster:id,name,location_id', 'cluster.location:id,uuid', 'storages' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->map(fn (Node $node) => [
                    'id' => $node->id,
                    'name' => $node->name,
                    'cluster' => $node->cluster->name,
                    'location' => $node->cluster->location->uuid,
                    'maintenance' => $node->is_maintenance,
                    'storages' => $node->storages
                        ->filter(fn (Storage $storage) => $storage->supportsVmDisks())
                        ->map(fn (Storage $storage) => [
                            'id' => $storage->id,
                            'name' => $storage->name,
                            'type' => $storage->type,
                            'use_for_servers' => $storage->use_for_servers,
                            'free_mib' => max(0, (int) $placer->freeDiskMib($storage, $node)),
                        ])
                        ->values(),
                ]),
        ]);
    }

    public function store(PlacementGroupRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $group = PlacementGroup::create($request->groupData());
            $group->nodes()->sync($request->nodeIds());
            $group->storages()->sync($request->storageIds());
        });

        $this->toast('success', 'Placement group created.');

        return to_route('admin.placement-groups.index');
    }

    public function update(PlacementGroupRequest $request, PlacementGroup $placementGroup): RedirectResponse
    {
        DB::transaction(function () use ($request, $placementGroup) {
            $placementGroup->update($request->groupData());
            $placementGroup->nodes()->sync($request->nodeIds());
            $placementGroup->storages()->sync($request->storageIds());
        });

        $this->toast('success', 'Placement group updated.');

        return to_route('admin.placement-groups.index');
    }

    public function destroy(PlacementGroup $placementGroup): RedirectResponse
    {
        // Packages would silently fall back to any node (docs/adr/0020 §5).
        $packages = $placementGroup->packages()->count();

        if ($packages > 0) {
            $this->toast('error', ':count packages use this placement group. Remove it from them first.', ['count' => (string) $packages]);

            return back();
        }

        $placementGroup->delete();

        $this->toast('success', 'Placement group deleted.');

        return to_route('admin.placement-groups.index');
    }
}
