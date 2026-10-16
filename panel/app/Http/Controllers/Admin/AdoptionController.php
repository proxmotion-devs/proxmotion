<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Migration\AdoptServer;
use App\Enums\NetworkModel;
use App\Http\Controllers\Controller;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\Package;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Take over VMs that already exist on Proxmox. Nothing is changed on Proxmox.
 */
class AdoptionController extends Controller
{
    public function create(Request $request, AdoptServer $adopt): Response
    {
        $cluster = Cluster::query()->where('uuid', $request->string('cluster'))->first();
        $candidates = [];
        $error = null;

        if ($cluster !== null) {
            try {
                $candidates = array_map(fn (VmResource $vm) => [
                    'vmid' => $vm->vmid,
                    'name' => $vm->name,
                    'node' => $vm->node,
                    'status' => $vm->status,
                    'cpus' => $vm->cpus,
                    'memory_bytes' => $vm->memoryBytes,
                    'disk_bytes' => $vm->diskBytes,
                ], $adopt->candidates($cluster));
            } catch (ProxmoxException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('admin/servers/Adopt', [
            'clusters' => Cluster::query()->orderBy('name')->get(['uuid', 'name', 'resource_pool'])
                ->map(fn (Cluster $c) => ['value' => $c->uuid, 'label' => $c->name, 'resource_pool' => $c->resource_pool]),
            'cluster' => $cluster?->uuid,
            'candidates' => $candidates,
            'error' => $error,
            'packages' => Package::query()->orderBy('name')->get(['uuid', 'name'])
                ->map(fn (Package $package) => ['value' => $package->uuid, 'label' => $package->name]),
            'addresses' => $cluster === null ? [] : IpAddress::query()
                ->whereNull('server_id')
                ->whereHas('pool', fn ($query) => $query->where('location_id', $cluster->location_id))
                ->orderBy('id')
                ->limit(2000)
                ->get(['id', 'address'])
                ->map(fn (IpAddress $address) => ['value' => $address->id, 'label' => $address->address]),
        ]);
    }

    public function store(Request $request, AdoptServer $adopt, #[CurrentUser] User $admin): RedirectResponse
    {
        $validated = $request->validate([
            'cluster' => ['required', 'string', Rule::exists('clusters', 'uuid')],
            'vmid' => ['required', 'integer', 'min:100'],
            'external_id' => ['nullable', 'string', 'max:191', Rule::unique('servers', 'external_id')],
            'external_client_id' => ['nullable', 'string', 'max:191'],
            'package' => ['nullable', 'string', Rule::exists('packages', 'uuid')],
            'network_model' => ['required', Rule::enum(NetworkModel::class)],
            'addresses' => ['array'],
            'addresses.*' => ['integer', 'distinct'],
        ]);

        try {
            $server = $adopt->adopt(
                cluster: Cluster::query()->where('uuid', $validated['cluster'])->firstOrFail(),
                vmid: (int) $validated['vmid'],
                package: isset($validated['package']) ? Package::query()->where('uuid', $validated['package'])->firstOrFail() : null,
                networkModel: NetworkModel::from($validated['network_model']),
                ipAddressIds: array_values(array_map(intval(...), $validated['addresses'] ?? [])),
                actor: $admin,
                externalId: isset($validated['external_id']) ? trim($validated['external_id']) : null,
                externalClientId: isset($validated['external_client_id']) ? trim($validated['external_client_id']) : null,
            );
        } catch (ProvisioningException $e) {
            throw ValidationException::withMessages(['vmid' => $e->translatedMessage()]);
        } catch (ProxmoxException $e) {
            throw ValidationException::withMessages(['vmid' => __('Could not connect to Proxmox: :error', ['error' => $e->getMessage()])]);
        }

        $this->toast('success', 'VM :vmid is now managed by the panel.', ['vmid' => (string) $server->vmid]);

        return to_route('admin.servers.show', $server);
    }
}
