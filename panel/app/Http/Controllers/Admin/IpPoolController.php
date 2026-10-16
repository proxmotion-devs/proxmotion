<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Networking\AddIpAddresses;
use App\Domain\Networking\GenerateIpAddresses;
use App\Domain\Networking\ProtectedAddresses;
use App\Http\Controllers\Admin\Concerns\ReportsAddedAddresses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IpPoolRequest;
use App\Models\DnsProvider;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class IpPoolController extends Controller
{
    use ReportsAddedAddresses;

    public function index(): Response
    {
        return Inertia::render('admin/ip-pools/Index', [
            'dnsProviders' => $this->dnsProviderOptions(),
            'pools' => IpPool::query()
                ->with(['location:id,uuid,name', 'dnsProvider:id,uuid'])
                ->withCount([
                    'addresses',
                    'addresses as reserved_count' => fn ($query) => $query->whereNull('server_id')->where('is_reserved', true),
                    'addresses as assigned_count' => fn ($query) => $query->whereNotNull('server_id'),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (IpPool $pool) => [
                    ...$this->poolProps($pool),
                    'addresses_count' => $pool->getAttribute('addresses_count'),
                    'reserved_count' => $pool->getAttribute('reserved_count'),
                    'assigned_count' => $pool->getAttribute('assigned_count'),
                ]),
            'locations' => Location::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Location $location) => ['uuid' => $location->uuid, 'name' => $location->name]),
        ]);
    }

    public function store(IpPoolRequest $request, GenerateIpAddresses $generate, AddIpAddresses $add): RedirectResponse
    {
        $pool = DB::transaction(function () use ($request, $generate, $add) {
            $pool = IpPool::create($request->poolData());

            match ($request->addMode()) {
                'list' => $this->reportAddedAddresses($add->handle($pool, $request->addressesToAdd())),
                'network' => $this->generateWholeNetwork($pool, $generate),
                default => $this->toast('success', 'IP pool created. Add its addresses next.'),
            };

            return $pool;
        });

        return to_route('admin.ip-pools.show', $pool);
    }

    private function generateWholeNetwork(IpPool $pool, GenerateIpAddresses $generate): void
    {
        try {
            $added = $generate->handle($pool);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'network' => __('This network is too large to add at once (max :max addresses). Use a smaller network.', [
                    'max' => (string) GenerateIpAddresses::MAX_ADDRESSES,
                ]),
            ]);
        }

        $this->toast('success', ':count addresses added.', ['count' => (string) $added]);
    }

    public function show(Request $request, IpPool $ipPool, ProtectedAddresses $protected): Response
    {
        $ipPool->load(['location:id,uuid,name', 'dnsProvider:id,uuid,name,driver']);
        $search = trim($request->string('search')->toString());

        return Inertia::render('admin/ip-pools/Show', [
            'pool' => $this->poolProps($ipPool),
            'dnsProviders' => $this->dnsProviderOptions(),
            'rdns' => [
                'provider' => $ipPool->dnsProvider?->name,
                'record_only' => $ipPool->dnsProvider === null || $ipPool->dnsProvider->driver === 'none',
                'pending' => $ipPool->addresses()->whereNotNull('ptr')->whereNull('ptr_synced_at')->count(),
            ],
            'counts' => [
                'total' => $ipPool->addresses()->count(),
                'assigned' => $ipPool->addresses()->whereNotNull('server_id')->count(),
                'reserved' => $ipPool->addresses()->whereNull('server_id')->where('is_reserved', true)->count(),
            ],
            'search' => $search,
            'protected' => $protected->for($ipPool),
            'addresses' => $ipPool->addresses()
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($query) => $query->where('address', 'like', "%{$search}%")->orWhere('notes', 'like', "%{$search}%"),
                ))
                ->with('server:id,uuid,hostname')
                ->orderBy('id')
                ->paginate(100)
                ->withQueryString()
                ->through(fn (IpAddress $address) => [
                    'id' => $address->id,
                    'address' => $address->address,
                    'mac_address' => $address->mac_address,
                    'is_reserved' => $address->is_reserved,
                    'notes' => $address->notes,
                    'ptr' => $address->ptr,
                    'ptr_pending' => $address->ptr !== null && $address->ptr_synced_at === null,
                    'server' => $address->server === null ? null : [
                        'uuid' => $address->server->uuid,
                        'hostname' => $address->server->hostname,
                    ],
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
        $assigned = $ipPool->addresses()->whereNotNull('server_id')->count();

        if ($assigned > 0) {
            $this->toast('error', ':count addresses of this pool are assigned to servers. Delete those servers first.', ['count' => (string) $assigned]);

            return back();
        }

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
            'dns_provider' => $pool->dnsProvider?->uuid,
            'reverse_zone' => $pool->reverse_zone,
            'rdns_require_forward' => $pool->rdns_require_forward,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function dnsProviderOptions(): array
    {
        return array_values(DnsProvider::query()->orderBy('name')->get(['uuid', 'name'])
            ->map(fn (DnsProvider $provider) => ['value' => $provider->uuid, 'label' => $provider->name])
            ->all());
    }
}
