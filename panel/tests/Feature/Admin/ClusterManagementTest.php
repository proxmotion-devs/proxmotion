<?php

namespace Tests\Feature\Admin;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Jobs\SyncCluster;
use App\Models\Cluster;
use App\Models\Location;
use App\Models\Node;
use App\Models\Storage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesProxmox;

class ClusterManagementTest extends AdminTestCase
{
    use FakesProxmox;

    /**
     * @return array<string, mixed>
     */
    private function payload(Location $location, array $overrides = []): array
    {
        return [
            'location' => $location->uuid,
            'name' => 'fra1-cluster',
            'hosts' => ['pve1.example.com', '192.0.2.12'],
            'port' => 8006,
            'token_id' => 'proxmotion@pve!panel',
            'token_secret' => 'a-secret',
            'verify_tls' => true,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_a_cluster_is_saved_after_a_successful_connection_test_and_synced()
    {
        Queue::fake();
        $this->fakeProxmox();
        $location = Location::factory()->create();

        $response = $this->post(route('admin.clusters.store'), $this->payload($location));

        $cluster = Cluster::query()->sole();
        $response->assertRedirect(route('admin.clusters.show', $cluster));
        $this->assertSame(['pve1.example.com', '192.0.2.12'], $cluster->hosts);
        $this->assertSame('a-secret', $cluster->token_secret);
        Queue::assertPushed(SyncCluster::class, fn (SyncCluster $job) => $job->cluster->is($cluster));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api2/json/version'));
    }

    public function test_a_cluster_is_not_saved_when_proxmox_rejects_the_token()
    {
        Queue::fake();
        Http::preventStrayRequests()->fake(['*' => Http::response(['data' => null], 401)]);
        $location = Location::factory()->create();

        $this->post(route('admin.clusters.store'), $this->payload($location))
            ->assertSessionHasErrors('hosts');

        $this->assertDatabaseCount('clusters', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_token_without_read_permissions_is_rejected()
    {
        Queue::fake();
        // /version works for any valid token; /cluster/status needs Sys.Audit.
        $this->fakeProxmox([
            '*/api2/json/cluster/status' => Http::response(['data' => null], 403, []),
        ]);
        $location = Location::factory()->create();

        $this->post(route('admin.clusters.store'), $this->payload($location))
            ->assertSessionHasErrors('token_id');

        $this->assertDatabaseCount('clusters', 0);
    }

    public function test_self_signed_certificates_get_a_clear_error()
    {
        Http::preventStrayRequests()->fake([
            '*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: self-signed certificate'),
        ]);
        $location = Location::factory()->create();

        $this->post(route('admin.clusters.store'), $this->payload($location))
            ->assertSessionHasErrors(['hosts' => __('The TLS certificate of the Proxmox server could not be verified. Install a valid certificate (e.g. Let\'s Encrypt) or turn off "Verify TLS certificate" on a trusted network.')]);
    }

    public function test_input_is_validated()
    {
        $location = Location::factory()->create();

        $this->post(route('admin.clusters.store'), $this->payload($location, [
            'hosts' => ['https://pve1.example.com:8006'],
            'token_id' => 'root',
            'token_secret' => '',
        ]))->assertSessionHasErrors(['hosts.0', 'token_id', 'token_secret']);
    }

    public function test_updating_without_a_secret_keeps_the_stored_one()
    {
        Queue::fake();
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create(['token_secret' => 'stored-secret']);

        $this->put(route('admin.clusters.update', $cluster), $this->payload($cluster->location, [
            'name' => 'renamed',
            'token_secret' => '',
        ]))->assertSessionHasNoErrors();

        $cluster->refresh();
        $this->assertSame('renamed', $cluster->name);
        $this->assertSame('stored-secret', $cluster->token_secret);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'PVEAPIToken=proxmotion@pve!panel=stored-secret'));
    }

    public function test_the_secret_is_never_sent_to_the_browser()
    {
        $cluster = Cluster::factory()->create(['token_secret' => 'top-secret-value']);

        $this->get(route('admin.clusters.index'))->assertOk()->assertDontSee('top-secret-value');
        $this->get(route('admin.clusters.show', $cluster))->assertOk()->assertDontSee('top-secret-value');
    }

    public function test_the_cluster_page_shows_synced_infrastructure()
    {
        $this->fakeProxmox();
        $cluster = Cluster::factory()->create();
        (new SyncCluster($cluster))->handle(app(ClusterSynchronizer::class));

        $this->get(route('admin.clusters.show', $cluster))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/clusters/Show')
                ->where('cluster.type', 'cluster')
                ->has('nodes', 2)
                ->has('nodes.0.storages', 3)
                ->has('templates', 1));
    }

    public function test_sync_can_be_triggered()
    {
        Queue::fake();
        $cluster = Cluster::factory()->create();

        $this->post(route('admin.clusters.sync', $cluster))->assertRedirect();

        Queue::assertPushed(SyncCluster::class);
    }

    public function test_node_placement_settings_can_be_changed()
    {
        $node = Node::factory()->create();

        $this->patch(route('admin.nodes.update', $node), [
            'is_maintenance' => true,
            'cpu_overcommit' => 2.5,
            'memory_overcommit' => 1,
            'disk_overcommit' => 1.2,
            'max_servers' => 50,
        ])->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertTrue($node->is_maintenance);
        $this->assertSame('2.50', $node->cpu_overcommit);
        $this->assertSame(50, $node->max_servers);
    }

    public function test_only_storages_that_accept_vm_disks_can_be_used_for_servers()
    {
        $node = Node::factory()->create();
        $images = $node->storages()->forceCreate(['name' => 'local-lvm', 'type' => 'lvmthin', 'content' => ['images', 'rootdir']]);
        $isos = $node->storages()->forceCreate(['name' => 'local', 'type' => 'dir', 'content' => ['iso', 'backup']]);

        $this->patch(route('admin.storages.update', $images), ['use_for_servers' => true])->assertSessionHasNoErrors();
        $this->patch(route('admin.storages.update', $isos), ['use_for_servers' => true])->assertSessionHasErrors('use_for_servers');

        $this->assertTrue(Storage::query()->findOrFail($images->id)->use_for_servers);
        $this->assertFalse(Storage::query()->findOrFail($isos->id)->use_for_servers);
    }

    public function test_deleting_a_cluster_only_removes_it_from_the_panel()
    {
        Http::preventStrayRequests()->fake();
        $cluster = Cluster::factory()->create();

        $this->delete(route('admin.clusters.destroy', $cluster))->assertRedirect(route('admin.clusters.index'));

        $this->assertModelMissing($cluster);
        Http::assertNothingSent();
    }
}
