<?php

namespace Tests\Integration;

use App\Enums\DriftType;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\ServerDrift;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * Drift reports (ADR 0005) from what a real sync reads: changes made outside
 * the panel (cores, power, a deleted VM, a new VM in the pool) are reported,
 * and resolved once Proxmox matches the panel again. Needs no spare address.
 */
class DriftTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_changes_outside_the_panel_are_reported_and_resolved()
    {
        $server = $this->adoptTestVm('proxmotion-drift-test');
        $this->runTask($server, TaskType::Start);
        $node = $server->node->name;
        $client = $this->client();
        $qemu = $client->qemu($node);
        $vmid = $server->vmid;

        $this->waitForGuest($vmid, fn ($guest) => $guest?->status === 'running');
        $this->syncCluster();
        $this->assertSame([], $this->openDrifts($server->vmid), 'A freshly adopted, running server has drifts: '.$this->describeDrifts($vmid));

        // Stopped and given another core in the Proxmox UI.
        $client->taskWaiter()->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
        $qemu->updateConfig($server->vmid, ['cores' => $server->cpu_cores + 1]);
        $this->waitForGuest($vmid, fn ($guest) => $guest?->status === 'stopped' && $guest->cpus === $server->cpu_cores + 1);
        $this->syncCluster();

        $this->assertEqualsCanonicalizing(
            [DriftType::PowerMismatch->value, DriftType::ResourceMismatch->value],
            $this->openDrifts($server->vmid),
        );
        $resources = ServerDrift::query()->where('vmid', $server->vmid)->where('type', DriftType::ResourceMismatch)->sole();
        $this->assertSame($server->cpu_cores + 1, $resources->actual['cpu_cores'] ?? null);

        // Put back as the panel has it.
        $qemu->updateConfig($server->vmid, ['cores' => $server->cpu_cores]);
        $client->taskWaiter()->wait($qemu->power($server->vmid, 'start'), timeoutSeconds: 300);
        $this->waitForGuest($vmid, fn ($guest) => $guest?->status === 'running' && $guest->cpus === $server->cpu_cores);
        $this->syncCluster();
        $this->assertSame([], $this->openDrifts($server->vmid), 'Drifts were not resolved after fixing the VM: '.$this->describeDrifts($vmid));

        // A VM added to the pool by hand.
        $unmanaged = $this->cloneTestVm('proxmotion-drift-unmanaged');
        $this->waitForGuest($unmanaged, fn ($guest) => $guest?->pool === $this->pool());
        $this->syncCluster();
        $this->assertSame([DriftType::UnmanagedVm->value], $this->openDrifts($unmanaged));

        // The server's VM deleted in the Proxmox UI.
        $client->taskWaiter()->wait($qemu->power($server->vmid, 'stop'), timeoutSeconds: 120);
        $client->taskWaiter()->wait($qemu->destroy($server->vmid), timeoutSeconds: 600);
        $this->waitForGuest($vmid, fn ($guest) => $guest === null);
        $this->syncCluster();
        $this->assertSame([DriftType::MissingVm->value], $this->openDrifts($server->vmid));
        $this->assertNotNull(Server::query()->find($server->id), 'The server record was removed.');
    }

    private function describeDrifts(int $vmid): string
    {
        return ServerDrift::query()->where('vmid', $vmid)->whereNull('resolved_at')->get()
            ->map(fn (ServerDrift $drift) => "{$drift->type->value} expected ".json_encode($drift->expected).' actual '.json_encode($drift->actual))
            ->implode('; ');
    }

    /**
     * @return list<string>
     */
    private function openDrifts(int $vmid): array
    {
        return ServerDrift::query()
            ->where('cluster_id', $this->cluster->id)
            ->where('vmid', $vmid)
            ->whereNull('resolved_at')
            ->get()
            ->map(fn (ServerDrift $drift) => $drift->type->value)
            ->values()
            ->all();
    }
}
