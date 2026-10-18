<?php

namespace Tests\Feature\Provisioning;

use App\Enums\NodeStatus;
use App\Enums\ServerStatus;
use App\Models\Cluster;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Placement\NodePlacer;
use App\Provisioning\Placement\Placement;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\TestCase;

class NodePlacerTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
    }

    private function place(): Placement
    {
        return app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr0');
    }

    public function test_the_least_used_node_is_chosen()
    {
        $pve2 = $this->addNode('pve2');
        Server::factory()->for($this->node)->create(['memory_mib' => 32768]);

        $placement = $this->place();

        $this->assertTrue($placement->node->is($pve2));
        $this->assertSame('local-lvm', $placement->storage->name);
    }

    public function test_the_template_on_the_same_node_is_preferred()
    {
        $this->assertSame(9000, $this->place()->template->vmid);
    }

    public function test_offline_and_maintenance_nodes_are_skipped()
    {
        $this->node->update(['is_maintenance' => true]);
        $this->expectNoCapacity();
    }

    public function test_offline_nodes_are_skipped()
    {
        $this->node->forceFill(['status' => NodeStatus::Offline])->save();

        $this->expectException(ProvisioningException::class);
        $this->place();
    }

    public function test_memory_over_commit_is_respected()
    {
        // 64 GiB node, 4 GiB package: 15 servers already booked leave room for one more.
        Server::factory()->count(15)->for($this->node)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);
        $this->place();

        Server::factory()->for($this->node)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);
        $this->expectNoCapacity();
    }

    public function test_terminated_servers_do_not_count()
    {
        Server::factory()->count(16)->for($this->node)->status(ServerStatus::Terminated)->create(['memory_mib' => 4096, 'cpu_cores' => 1]);

        $this->assertTrue($this->place()->node->is($this->node));
    }

    public function test_cpu_over_commit_is_respected()
    {
        $this->node->update(['cpu_overcommit' => 1]);
        Server::factory()->count(8)->for($this->node)->create(['cpu_cores' => 2, 'memory_mib' => 512]);

        $this->expectNoCapacity();
    }

    public function test_max_servers_is_respected()
    {
        $this->node->update(['max_servers' => 1]);
        Server::factory()->for($this->node)->create(['memory_mib' => 512]);

        $this->expectNoCapacity();
    }

    public function test_storage_must_be_enabled_and_have_room()
    {
        $this->storage->update(['use_for_servers' => false]);
        $this->expectNoCapacity();
    }

    public function test_the_bridge_must_exist_on_the_node()
    {
        $this->expectException(ProvisioningException::class);

        app(NodePlacer::class)->place($this->location, ServerSpec::fromPackage($this->package), $this->osTemplate, 'vmbr9');
    }

    public function test_an_os_template_without_a_proxmox_template_cannot_be_placed()
    {
        $this->osTemplate->proxmoxTemplates()->detach();

        $this->expectExceptionMessage('This OS template is not available on any cluster in this location.');

        $this->place();
    }

    public function test_the_package_clusters_decide_where_a_server_goes()
    {
        // A second, busier cluster in the same location, e.g. other CPUs.
        $first = $this->cluster;
        $this->cluster = Cluster::factory()->for($this->location)->create();
        $other = $this->addNode('r9-1');
        $this->osTemplate->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id, 'node_id' => $other->id, 'vmid' => 9100, 'name' => 'debian-12',
        ]));
        Server::factory()->for($other)->create(['memory_mib' => 32768]);
        $spec = ServerSpec::fromPackage($this->package);
        $placer = app(NodePlacer::class);

        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0')->node->is($this->node), 'Without clusters, the least used node anywhere.');
        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', [$this->cluster->id])->node->is($other));
        $this->assertTrue($placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', [$first->id])->node->is($this->node));

        $elsewhere = Cluster::factory()->create();
        $this->expectExceptionMessage('None of the clusters this package may use is in this location and has this OS template.');
        $placer->place($this->location, $spec, $this->osTemplate, 'vmbr0', [$elsewhere->id]);
    }

    public function test_server_creation_uses_the_package_clusters()
    {
        $this->package->clusters()->attach(Cluster::factory()->for($this->location)->create());

        $this->expectExceptionMessage('None of the clusters this package may use is in this location and has this OS template.');
        app(ServerCreator::class)->create($this->order());
    }

    private function expectNoCapacity(): void
    {
        $this->expectExceptionMessage('No node in this location has enough free capacity for this server.');

        $this->place();
    }
}
