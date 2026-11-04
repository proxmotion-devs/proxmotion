<?php

declare(strict_types=1);

namespace App\Domain\Servers;

use App\Enums\ServerStatus;
use App\Models\Cluster;
use App\Models\Server;
use App\Proxmox\Data\VmResource;
use App\Proxmox\Exceptions\ProxmoxException;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Support\Collection;

/**
 * The power state Proxmox reports right now for the servers on one page of
 * the server list: one /cluster/resources per cluster, never one request
 * per server. A cluster that does not answer leaves its servers unknown
 * (null); nothing is changed or recorded.
 */
final readonly class ListedPowerStates
{
    public function __construct(private ProxmoxClientFactory $clients) {}

    /**
     * @param  iterable<Server>  $servers
     * @return array<string, string|null> server uuid => "running", "stopped", … or null when unknown
     */
    public function read(iterable $servers): array
    {
        /** @var Collection<int, Server> $listed */
        $listed = collect($servers)->reject(fn (Server $server) => $server->status === ServerStatus::Terminated);
        $states = $listed->mapWithKeys(fn (Server $server) => [$server->uuid => null])->all();

        $clusters = Cluster::query()->whereIn('id', $listed->pluck('cluster_id')->unique())->get();

        foreach ($clusters as $cluster) {
            try {
                $guests = collect($this->clients->forCluster($cluster)->cluster()->guests())
                    ->filter(fn (VmResource $guest) => $guest->type === 'qemu' && ! $guest->template)
                    ->keyBy('vmid');
            } catch (ProxmoxException) {
                continue;
            }

            foreach ($listed->where('cluster_id', $cluster->id) as $server) {
                $states[$server->uuid] = $guests->get($server->vmid)?->status;
            }
        }

        return $states;
    }
}
