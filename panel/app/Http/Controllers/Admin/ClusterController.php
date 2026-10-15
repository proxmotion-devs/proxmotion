<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClusterRequest;
use App\Jobs\SyncCluster;
use App\Models\Cluster;
use App\Models\Location;
use App\Models\NetworkBridge;
use App\Models\Node;
use App\Models\Storage;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\Exceptions\ProxmoxRequestFailed;
use App\Proxmox\Exceptions\ProxmoxTlsVerificationFailed;
use App\Proxmox\ProxmoxClientFactory;
use App\Proxmox\ProxmoxConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClusterController extends Controller
{
    public function __construct(private readonly ProxmoxClientFactory $clients) {}

    public function index(): Response
    {
        return Inertia::render('admin/clusters/Index', [
            'clusters' => Cluster::query()
                ->with('location:id,uuid,name')
                ->withCount('nodes')
                ->orderBy('name')
                ->get()
                ->map(fn (Cluster $cluster) => $this->clusterProps($cluster)),
            'locations' => $this->locationOptions(),
        ]);
    }

    public function store(ClusterRequest $request): RedirectResponse
    {
        $data = $request->clusterData();
        $this->assertConnectionWorks($data);

        $cluster = Cluster::create($data);
        SyncCluster::dispatch($cluster);

        $this->toast('success', 'Cluster added. Syncing nodes from Proxmox…');

        return to_route('admin.clusters.show', $cluster);
    }

    public function show(Cluster $cluster): Response
    {
        $cluster->load([
            'location:id,uuid,name',
            'nodes' => fn ($query) => $query->orderBy('name'),
            'nodes.storages' => fn ($query) => $query->orderBy('name'),
            'nodes.bridges' => fn ($query) => $query->orderBy('name'),
            'proxmoxTemplates' => fn ($query) => $query->with('node:id,name')->orderBy('vmid'),
        ]);

        return Inertia::render('admin/clusters/Show', [
            'cluster' => $this->clusterProps($cluster),
            'locations' => $this->locationOptions(),
            'nodes' => $cluster->nodes->map(fn (Node $node) => [
                'uuid' => $node->uuid,
                'name' => $node->name,
                'status' => $node->status,
                'is_maintenance' => $node->is_maintenance,
                'cpu_cores' => $node->cpu_cores,
                'memory_bytes' => $node->memory_bytes,
                'cpu_overcommit' => (float) $node->cpu_overcommit,
                'memory_overcommit' => (float) $node->memory_overcommit,
                'disk_overcommit' => (float) $node->disk_overcommit,
                'max_servers' => $node->max_servers,
                'last_seen_at' => $node->last_seen_at?->toIso8601String(),
                'storages' => $node->storages->map(fn (Storage $storage) => [
                    'id' => $storage->id,
                    'name' => $storage->name,
                    'type' => $storage->type,
                    'content' => $storage->content,
                    'is_shared' => $storage->is_shared,
                    'is_available' => $storage->is_available,
                    'use_for_servers' => $storage->use_for_servers,
                    'supports_vm_disks' => $storage->supportsVmDisks(),
                    'total_bytes' => $storage->total_bytes,
                    'used_bytes' => $storage->used_bytes,
                ]),
                'bridges' => $node->bridges->map(fn (NetworkBridge $bridge) => [
                    'name' => $bridge->name,
                    'is_active' => $bridge->is_active,
                    'is_vlan_aware' => $bridge->is_vlan_aware,
                    'cidr' => $bridge->cidr,
                    'comments' => $bridge->comments,
                ]),
            ]),
            'templates' => $cluster->proxmoxTemplates->map(fn ($template) => [
                'vmid' => $template->vmid,
                'name' => $template->name,
                'node' => $template->node->name,
            ]),
        ]);
    }

    public function update(ClusterRequest $request, Cluster $cluster): RedirectResponse
    {
        $data = $request->clusterData();
        $this->assertConnectionWorks([...$data, 'token_secret' => $data['token_secret'] ?? $cluster->token_secret]);

        $cluster->update($data);
        SyncCluster::dispatch($cluster);

        $this->toast('success', 'Cluster updated.');

        return back();
    }

    public function sync(Cluster $cluster): RedirectResponse
    {
        SyncCluster::dispatch($cluster);

        $this->toast('info', 'Sync started.');

        return back();
    }

    public function destroy(Cluster $cluster): RedirectResponse
    {
        // Servers will reference nodes from Phase 2 on; deleting must be blocked then.
        $cluster->delete();

        $this->toast('success', 'Cluster removed from the panel. Nothing was changed on Proxmox.');

        return to_route('admin.clusters.index');
    }

    /**
     * Refuse to save a connection Proxmox doesn't accept.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertConnectionWorks(array $data): void
    {
        /** @var list<string> $hosts */
        $hosts = $data['hosts'];

        $connection = new ProxmoxConnection(
            hosts: $hosts,
            port: (int) $data['port'],
            tokenId: (string) $data['token_id'],
            tokenSecret: (string) $data['token_secret'],
            verifyTls: (bool) $data['verify_tls'],
            timeoutSeconds: 10,
        );

        try {
            $client = $this->clients->make($connection);
            $client->version();
            // /version needs no privileges; /cluster/status needs Sys.Audit like the sync does.
            $client->cluster()->status();
        } catch (ProxmoxTlsVerificationFailed) {
            throw ValidationException::withMessages([
                'hosts' => __('The TLS certificate of the Proxmox server could not be verified. Install a valid certificate (e.g. Let\'s Encrypt) or turn off "Verify TLS certificate" on a trusted network.'),
            ]);
        } catch (ProxmoxException $e) {
            if ($e instanceof ProxmoxRequestFailed && $e->status === 403) {
                throw ValidationException::withMessages([
                    'token_id' => __('The API token is missing permissions (:error). Give it at least the PVEAuditor role on "/".', ['error' => $e->getMessage()]),
                ]);
            }

            throw ValidationException::withMessages([
                'hosts' => __('Could not connect to Proxmox: :error', ['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clusterProps(Cluster $cluster): array
    {
        return [
            'uuid' => $cluster->uuid,
            'name' => $cluster->name,
            'location' => ['uuid' => $cluster->location->uuid, 'name' => $cluster->location->name],
            'type' => $cluster->type,
            'proxmox_cluster_name' => $cluster->proxmox_cluster_name,
            'hosts' => $cluster->hosts,
            'port' => $cluster->port,
            'token_id' => $cluster->token_id,
            'verify_tls' => $cluster->verify_tls,
            'is_active' => $cluster->is_active,
            'pve_version' => $cluster->pve_version,
            'nodes_count' => $cluster->getAttribute('nodes_count') ?? $cluster->nodes->count(),
            'last_synced_at' => $cluster->last_synced_at?->toIso8601String(),
            'last_sync_error' => $cluster->last_sync_error,
        ];
    }

    /**
     * @return Collection<int, array{uuid: string, name: string}>
     */
    private function locationOptions(): Collection
    {
        return Location::query()->orderBy('name')->get(['uuid', 'name'])
            ->map(fn (Location $location) => ['uuid' => $location->uuid, 'name' => $location->name]);
    }
}
