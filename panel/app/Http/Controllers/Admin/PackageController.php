<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PackageRequest;
use App\Models\FirewallRuleset;
use App\Models\OsTemplate;
use App\Models\Package;
use App\Models\PlacementGroup;
use App\Provisioning\ResourceLimits;
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
                ->with(['placementGroups:id', 'osTemplates:id', 'firewallRuleset:id,uuid'])
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
                    ...ResourceLimits::of($package)->columns(),
                    'reinstall_limit' => $package->reinstall_limit,
                    'reinstall_period_days' => $package->reinstall_period_days,
                    'ipv4_count' => $package->ipv4_count,
                    'ipv6_count' => $package->ipv6_count,
                    'snapshot_slots' => $package->snapshot_slots,
                    'backup_slots' => $package->backup_slots,
                    'network_models' => $package->network_models,
                    'is_active' => $package->is_active,
                    'sort_order' => $package->sort_order,
                    'placement_group_ids' => $package->placementGroups->pluck('id'),
                    'os_template_ids' => $package->osTemplates->pluck('id'),
                    'firewall_ruleset' => $package->firewallRuleset?->uuid,
                ]),
            'osTemplates' => OsTemplate::query()->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (OsTemplate $template) => ['id' => $template->id, 'name' => $template->name, 'active' => $template->is_active]),
            'firewallRulesets' => FirewallRuleset::query()->orderBy('name')->get(['uuid', 'name', 'is_active'])
                ->map(fn (FirewallRuleset $ruleset) => ['value' => $ruleset->uuid, 'label' => $ruleset->is_active ? $ruleset->name : $ruleset->name.' ('.__('inactive').')']),
            'placementGroups' => PlacementGroup::query()->with('location:id,name')->orderBy('name')->get(['id', 'name', 'location_id'])
                ->map(fn (PlacementGroup $group) => ['id' => $group->id, 'name' => $group->name, 'location' => $group->location->name]),
        ]);
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $package = Package::create($request->packageData());
            $package->placementGroups()->sync($request->placementGroupIds());
            $package->osTemplates()->sync($request->osTemplateIds());
        });

        $this->toast('success', 'Package created.');

        return to_route('admin.packages.index');
    }

    public function update(PackageRequest $request, Package $package): RedirectResponse
    {
        DB::transaction(function () use ($request, $package) {
            $package->update($request->packageData());
            $package->placementGroups()->sync($request->placementGroupIds());
            $package->osTemplates()->sync($request->osTemplateIds());
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
