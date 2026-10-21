<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/locations/Index', [
            'locations' => Location::query()
                ->withCount(['clusters', 'ipPools'])
                ->orderBy('name')
                ->get()
                ->map(fn (Location $location) => [
                    'uuid' => $location->uuid,
                    'name' => $location->name,
                    'code' => $location->code,
                    'country_code' => $location->country_code,
                    'description' => $location->description,
                    'hostname_template' => $location->hostname_template,
                    'protected_networks' => $location->protected_networks ?? [],
                    'clusters_count' => $location->getAttribute('clusters_count'),
                    'ip_pools_count' => $location->getAttribute('ip_pools_count'),
                ]),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        Location::create($request->locationData());

        $this->toast('success', 'Location created.');

        return to_route('admin.locations.index');
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->locationData());

        $this->toast('success', 'Location updated.');

        return to_route('admin.locations.index');
    }

    public function destroy(Location $location): RedirectResponse
    {
        $counts = [
            'clusters' => $location->clusters()->count(),
            'pools' => $location->ipPools()->count(),
            'servers' => $location->servers()->count(),
            'groups' => $location->placementGroups()->count(),
        ];

        if (array_sum($counts) > 0) {
            $this->toast(
                'error',
                'This location still has :clusters clusters, :pools IP pools, :groups placement groups and :servers servers. Remove them first.',
                array_map(strval(...), $counts),
            );

            return back();
        }

        $location->delete();

        $this->toast('success', 'Location deleted.');

        return to_route('admin.locations.index');
    }
}
