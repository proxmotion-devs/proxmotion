<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Enums\ClusterType;
use App\Enums\NodeStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Proxmox\Exceptions\ProxmoxAuthenticationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesProxmox;
use Tests\TestCase;

class ClusterSynchronizerTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    public function test_it_mirrors_the_cluster_in_the_database()
    {
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create();

        app(ClusterSynchronizer::class)->sync($cluster);

        $cluster->refresh();
        $this->assertSame(ClusterType::Cluster, $cluster->type);
        $this->assertSame('dc1', $cluster->proxmox_cluster_name);
        $this->assertSame('8.4.1', $cluster->pve_version);
        $this->assertNotNull($cluster->last_synced_at);
        $this->assertNull($cluster->last_sync_error);

        $pve1 = $cluster->nodes()->where('name', 'pve1')->sole();
        $this->assertSame(NodeStatus::Online, $pve1->status);
        $this->assertSame(32, $pve1->cpu_cores);
        $this->assertSame(137438953472, $pve1->memory_bytes);
        $this->assertSame(NodeStatus::Offline, $cluster->nodes()->where('name', 'pve2')->sole()->status);

        $this->assertEqualsCanonicalizing(['local', 'local-lvm', 'ceph-vm'], $pve1->storages()->pluck('name')->all());
        $this->assertTrue($pve1->storages()->where('name', 'ceph-vm')->sole()->is_shared);

        $this->assertEqualsCanonicalizing(['vmbr0', 'vmbr1'], $pve1->bridges()->pluck('name')->all());

        // Only QEMU templates are imported; regular VMs and LXC templates are ignored.
        $this->assertSame([9000], $cluster->proxmoxTemplates()->pluck('vmid')->all());
    }

    public function test_it_detects_standalone_nodes()
    {
        $this->fakeProxmox(['*/cluster/status' => $this->proxmoxFixture('cluster-status-standalone')]);
        $cluster = Cluster::factory()->create();

        app(ClusterSynchronizer::class)->sync($cluster);

        $this->assertSame(ClusterType::Standalone, $cluster->refresh()->type);
        $this->assertNull($cluster->proxmox_cluster_name);
    }

    public function test_it_keeps_admin_settings_and_marks_missing_things_instead_of_deleting_them()
    {
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create();
        app(ClusterSynchronizer::class)->sync($cluster);

        $pve1 = $cluster->nodes()->where('name', 'pve1')->sole();
        $pve1->update(['is_maintenance' => true, 'cpu_overcommit' => 2]);
        $pve1->storages()->where('name', 'local-lvm')->update(['use_for_servers' => true]);
        $ghost = Node::factory()->for($cluster)->create(['name' => 'old-node']);

        app(ClusterSynchronizer::class)->sync($cluster);

        $pve1->refresh();
        $this->assertTrue($pve1->is_maintenance);
        $this->assertSame('2.00', $pve1->cpu_overcommit);
        $this->assertTrue($pve1->storages()->where('name', 'local-lvm')->sole()->use_for_servers);
        $this->assertSame(NodeStatus::Unknown, $ghost->refresh()->status);
    }

    public function test_storages_that_disappear_are_marked_unavailable()
    {
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create();
        app(ClusterSynchronizer::class)->sync($cluster);

        Http::swap(new Factory);
        $this->fakeProxmox(['*/cluster/resources?type=storage' => Http::response(['data' => []])]);
        app(ClusterSynchronizer::class)->sync($cluster);

        $storages = $cluster->nodes()->where('name', 'pve1')->sole()->storages;
        $this->assertCount(3, $storages);
        $this->assertTrue($storages->every(fn ($storage) => ! $storage->is_available));
    }

    public function test_errors_are_recorded_on_the_cluster()
    {
        Http::preventStrayRequests()->fake(['*' => Http::response(['data' => null], 401)]);
        $cluster = Cluster::factory()->create();

        try {
            app(ClusterSynchronizer::class)->sync($cluster);
            $this->fail('Expected an exception.');
        } catch (ProxmoxAuthenticationFailed) {
            $this->assertStringContainsString('rejected the API token', (string) $cluster->refresh()->last_sync_error);
            $this->assertNull($cluster->last_synced_at);
        }
    }

    public function test_the_secret_is_stored_encrypted()
    {
        $cluster = Cluster::factory()->create(['token_secret' => 'super-secret']);

        $raw = \DB::table('clusters')->where('id', $cluster->id)->value('token_secret');

        $this->assertNotSame('super-secret', $raw);
        $this->assertSame('super-secret', $cluster->refresh()->token_secret);
        $this->assertArrayNotHasKey('token_secret', $cluster->toArray());
    }
}
