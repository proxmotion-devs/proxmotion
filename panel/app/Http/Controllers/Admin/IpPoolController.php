<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\IpVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IpPoolRequest;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class IpPoolController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ip-pools/Index', [
            'pools' => IpPool::query()
                ->with('location:id,uuid,name')
                ->withCount([
                    'addresses',
                    'addresses as reserved_count' => fn ($query) => $query->where('is_reserved', true),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (IpPool $pool) => [
                    ...$this->poolProps($pool),
                    'addresses_count' => $pool->getAttribute('addresses_count'),
                    'reserved_count' => $pool->getAttribute('reserved_count'),
                ]),
            'locations' => Location::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Location $location) => ['uuid' => $location->uuid, 'name' => $location->name]),
        ]);
    }

    public function store(IpPoolRequest $request, GenerateIpAddresses $generate): RedirectResponse
    {
        $pool = DB::transaction(function () use ($request, $generate) {
            $pool = IpPool::create($request->poolData());

            if ($pool->version === IpVersion::V4) {
                try {
                    $generate->handle($pool);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        'network' => __('This network is too large to add at once (max :max addresses). Use a smaller network.', [
                            'max' => (string) GenerateIpAddresses::MAX_ADDRESSES,
                        ]),
                    ]);
                }
            }

            return $pool;
        });

        $this->toast('success', 'IP pool created.');

        return to_route('admin.ip-pools.show', $pool);
    }

    public function show(IpPool $ipPool): Response
    {
        $ipPool->load('location:id,uuid,name');

        return Inertia::render('admin/ip-pools/Show', [
            'pool' => $this->poolProps($ipPool),
            'addresses' => $ipPool->addresses()
                ->orderBy('id')
                ->paginate(100)
                ->through(fn (IpAddress $address) => [
                    'id' => $address->id,
                    'address' => $address->address,
                    'mac_address' => $address->mac_address,
                    'is_reserved' => $address->is_reserved,
                    'notes' => $address->notes,
                ]),
        ]);
    }

    public function update(IpPoolRequest $request, IpPool $ipPool): RedirectResponse
    {
        $ipPool->update($request->poolData());

        $this->toast('success', 'IP pool updated.');

        return back();
    }

    public function destroy(IpPool $ipPool): RedirectResponse
    {
        // From Phase 2, pools with assigned addresses must not be deletable.
        $ipPool->delete();

        $this->toast('success', 'IP pool deleted.');

        return to_route('admin.ip-pools.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function poolProps(IpPool $pool): array
    {
        return [
            'uuid' => $pool->uuid,
            'name' => $pool->name,
            'location' => ['uuid' => $pool->location->uuid, 'name' => $pool->location->name],
            'version' => $pool->version,
            'network_model' => $pool->network_model,
            'network' => $pool->network,
            'gateway' => $pool->gateway,
            'bridge' => $pool->bridge,
            'vlan_tag' => $pool->vlan_tag,
            'nameservers' => $pool->nameservers ?? [],
            'is_active' => $pool->is_active,
        ];
    }
}
