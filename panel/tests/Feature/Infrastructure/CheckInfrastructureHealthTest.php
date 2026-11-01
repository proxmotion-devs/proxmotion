<?php

namespace Tests\Feature\Infrastructure;

use App\Enums\NodeStatus;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesProxmox;
use Tests\TestCase;

class CheckInfrastructureHealthTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    public function test_it_reports_when_no_active_clusters_exist()
    {
        $this->artisan('infra:health')
            ->expectsOutputToContain('No active clusters found to check.')
            ->assertSuccessful();
    }

    public function test_it_diagnoses_active_cluster_successfully()
    {
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create([
            'name' => 'dc1',
            'hosts' => ['pve1.example.com'],
            'is_active' => true,
        ]);

        Node::factory()->create([
            'cluster_id' => $cluster->id,
            'name' => 'pve1',
            'status' => NodeStatus::Online,
        ]);

        $this->artisan('infra:health')
            ->expectsOutputToContain('Checking cluster: dc1')
            ->expectsOutputToContain('Proxmox VE connection OK')
            ->assertSuccessful();
    }
}
