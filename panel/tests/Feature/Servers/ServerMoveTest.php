<?php

namespace Tests\Feature\Servers;

use App\Domain\Networking\GenerateIpAddresses;
use App\Enums\AppInstallationStatus;
use App\Enums\ClusterType;
use App\Enums\NetworkModel;
use App\Enums\ServerMoveKind;
use App\Enums\ServerMoveStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WebhookEvent;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Location;
use App\Models\Node;
use App\Models\Server;
use App\Models\ServerMove;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Moves\MoveTarget;
use App\Provisioning\Moves\ServerMover;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Moving servers (docs/adr/0037): to another node of the cluster with
 * Proxmox's migrate, to another cluster of the location with remote_migrate
 * and the target's incoming move token, and to another location with new
 * addresses; refusals, failures and finishing a move whose VM already moved.
 */
class ServerMoveTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private FakeProxmoxCluster $other;

    private User $admin;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000);
        $this->proxmox->nodes = ['pve1', 'pve2'];
        $this->other = new FakeProxmoxCluster;
        $this->other->nodes = ['pveb1'];
        $this->other->hostNodes = ['pve-b.example.com' => 'pveb1'];
        FakeProxmoxCluster::installAll(['pve1.example.com' => $this->proxmox, 'pve-b.example.com' => $this->other]);

        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->addNode('pve2');
    }

    private function move(MoveTarget $target): ServerMove
    {
        return app(ServerMover::class)->request($this->server, $target, $this->admin, '127.0.0.1')->refresh();
    }

    private function assertRefused(MoveTarget $target, string $reason): void
    {
        try {
            $this->move($target);
            $this->fail('The move was not refused.');
        } catch (ProvisioningException $e) {
            $this->assertSame($reason, $e->getMessage());
        }

        $this->assertSame(0, ServerMove::query()->count());
    }

    /**
     * A second cluster in the given location, receiving moves through its
     * incoming token, with node pveb1 answering at pve-b.example.com.
     */
    private function otherCluster(?Location $location = null, bool $incoming = true): Node
    {
        $cluster = Cluster::factory()->for($location ?? $this->location)->create([
            'hosts' => ['pve-b.example.com'],
            'resource_pool' => 'proxmotion',
        ]);
        $cluster->forceFill([
            'type' => ClusterType::Cluster,
            'incoming_token_id' => $incoming ? 'incoming@pve!moves' : null,
            'incoming_token_secret' => $incoming ? 'incoming-secret-value' : null,
            'firewall_enabled' => true,
            'firewall_ebtables' => true,
        ])->save();

        $node = Node::factory()->for($cluster)->create(['name' => 'pveb1', 'cpu_cores' => 16, 'memory_bytes' => 64 * 1024 ** 3]);
        $node->forceFill(['address' => '192.0.2.21', 'api_host' => 'pve-b.example.com'])->save();
        $node->storages()->forceCreate([
            'name' => 'ceph', 'type' => 'rbd', 'content' => ['images'], 'is_shared' => true,
            'is_available' => true, 'use_for_servers' => true, 'total_bytes' => 500 * 1024 ** 3, 'used_bytes' => 0,
        ]);
        $node->bridges()->forceCreate(['name' => 'vmbr0', 'is_active' => true]);

        return $node;
    }

    public function test_a_server_moves_live_to_another_node_of_its_cluster()
    {
        $vmid = $this->server->vmid;
        $address = $this->server->ipAddresses()->sole()->address;
        $pve2 = Node::query()->where('name', 'pve2')->sole();

        $move = $this->move(new MoveTarget(node: $pve2));

        $task = $move->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(ServerMoveKind::Node, $move->kind);
        $this->assertSame(ServerMoveStatus::Succeeded, $move->status);
        $this->assertTrue($move->was_running);

        $migration = collect($this->proxmox->writes)->firstWhere('path', "/nodes/pve1/qemu/{$vmid}/migrate");
        $this->assertSame(['target' => 'pve2', 'online' => '1', 'with-local-disks' => '1', 'targetstorage' => 'local-lvm'], $migration['data']);
        $this->assertSame('pve2', $this->proxmox->vms[$vmid]['node']);

        $server = $this->server->refresh();
        $this->assertSame($pve2->id, $server->node_id);
        $this->assertSame($pve2->storages()->sole()->id, $server->storage_id);
        $this->assertSame($vmid, $server->vmid);
        $this->assertSame($address, $server->ipAddresses()->sole()->address);
        $this->assertSame(['check', 'migrate', 'record', 'verify'], array_column($task->steps, 'key'));
        $this->assertSame(['check', 'migrate', 'record', 'verify'], app(TaskPlan::class)->steps($task));
        // The firewall file is cluster-wide: nothing to rewrite.
        $this->assertSame([], $this->proxmox->remoteMigrations);

        $this->assertSame(1, AuditLog::query()->where('action', 'server.move_started')->where('user_id', $this->admin->id)->count());
        $this->assertSame(['hostname', 'kind', 'from', 'to', 'new_addresses'], array_keys(AuditLog::query()->where('action', 'server.moved')->sole()->properties));
        $this->assertSame(WebhookEvent::ServerMoved, WebhookEvent::forTask(TaskType::Move, true));
    }

    public function test_a_failed_migration_leaves_the_server_where_it_was()
    {
        $this->proxmox->failMigration = 'migration aborted';
        $pve1 = $this->server->node_id;

        $move = $this->move(new MoveTarget(node: Node::query()->where('name', 'pve2')->sole()));

        $this->assertSame(ServerMoveStatus::Failed, $move->status);
        $this->assertStringContainsString('migration aborted', (string) $move->error);
        $this->assertFalse($move->canFinish());
        $this->assertSame(TaskStatus::Failed, $move->tasks()->sole()->status);
        $this->assertSame($pve1, $this->server->refresh()->node_id);
        $this->assertSame('pve1', $this->proxmox->vms[$this->server->vmid]['node']);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.move_failed')->count());
    }

    public function test_moves_are_refused_when_they_cannot_work()
    {
        $pve2 = Node::query()->where('name', 'pve2')->sole();

        $this->assertRefused(new MoveTarget(node: $this->server->node), 'The server is already on this node.');

        $pve2->bridges()->update(['is_active' => false]);
        $this->assertRefused(new MoveTarget(node: $pve2), 'The chosen node does not have the bridge :bridge.');
        $pve2->bridges()->update(['is_active' => true]);

        $pve2->forceFill(['max_servers' => 0])->save();
        $this->assertRefused(new MoveTarget(node: $pve2), 'The chosen node does not have enough free CPU, memory or server slots for this server.');
        $pve2->forceFill(['max_servers' => null])->save();

        $this->proxmox->vms[$this->server->vmid]['config']['parent'] = 'before-upgrade';
        $this->assertRefused(new MoveTarget(node: $pve2), 'The VM has snapshots: delete them before moving the server.');
        unset($this->proxmox->vms[$this->server->vmid]['config']['parent']);

        // A linked clone's disk names the template's base image, which only its node has.
        $this->proxmox->vms[$this->server->vmid]['config']['scsi0'] = "local-lvm:base-9000-disk-0/vm-{$this->server->vmid}-disk-0,size=40960M";
        $this->assertRefused(new MoveTarget(node: $pve2), 'The server is a linked clone on local storage and cannot be moved to another node yet.');
    }

    public function test_a_running_app_script_or_routed_addresses_hold_a_move_back()
    {
        $pve2 = Node::query()->where('name', 'pve2')->sole();
        $installation = AppInstallation::factory()->for($this->server)->create(['status' => AppInstallationStatus::Installing]);

        $this->assertRefused(new MoveTarget(node: $pve2), 'An app script is running on this server: wait until it has finished.');
        $installation->forceFill(['status' => AppInstallationStatus::Installed])->save();

        $this->ipv4Pool->forceFill(['network_model' => NetworkModel::Routed])->save();
        $this->assertRefused(new MoveTarget(node: $pve2), 'Confirm that the provider routes the addresses of this server to the new node.');

        $move = $this->move(new MoveTarget(node: $pve2, routedConfirmed: true));
        $this->assertSame(ServerMoveStatus::Succeeded, $move->status);
    }

    public function test_a_server_moves_to_another_cluster_of_its_location()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $pveb1 = $this->otherCluster();
        $vmid = $this->server->vmid;
        $address = $this->server->ipAddresses()->sole()->address;
        $mac = $this->server->mac_address;

        $move = $this->move(new MoveTarget(node: $pveb1));

        $task = $move->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(ServerMoveKind::Cluster, $move->kind);
        $this->assertSame($vmid, $move->target_vmid, 'The VMID stays when it is free on the target.');

        $request = $this->proxmox->remoteMigrations[0];
        $this->assertSame('apitoken=PVEAPIToken=incoming@pve!moves=incoming-secret-value,host=pve-b.example.com,port=8006', $request['target-endpoint']);
        $this->assertSame(['vmid', 'target-endpoint', 'target-vmid', 'target-storage', 'target-bridge', 'online', 'delete'], array_keys($request));
        $this->assertSame(['ceph', 'vmbr0', '1', '1'], [$request['target-storage'], $request['target-bridge'], $request['online'], $request['delete']]);

        // The source copy is gone; the moved VM is in the target's pool, running, with its MAC and addresses.
        $this->assertArrayNotHasKey($vmid, $this->proxmox->vms);
        $vm = $this->other->vms[$vmid];
        $this->assertSame(['pveb1', 'proxmotion', 'running'], [$vm['node'], $vm['pool'], $vm['status']]);
        $this->assertStringContainsString(strtoupper((string) $mac), strtoupper((string) $vm['config']['net0']));
        $this->assertSame([$address], $vm['ipset']);
        $this->assertContains('PUT /pools/proxmotion', $this->other->writePaths());
        // The copied firewall is written again on the target.
        $this->assertContains("PUT /nodes/pveb1/qemu/{$vmid}/firewall/options", $this->other->writePaths());
        $this->assertSame('1', (string) $vm['firewall']['ipfilter']);

        $server = $this->server->refresh();
        $this->assertSame([$pveb1->cluster_id, $pveb1->id, $vmid, $this->location->id], [$server->cluster_id, $server->node_id, $server->vmid, $server->location_id]);
        $this->assertSame($address, $server->ipAddresses()->sole()->address);
        $this->assertSame(['check', 'migrate', 'record', 'adopt', 'firewall', 'verify'], array_column($task->steps, 'key'));
    }

    public function test_a_taken_vmid_on_the_target_gives_the_server_a_new_one()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $pveb1 = $this->otherCluster();
        $this->other->addVm($this->server->vmid, 'somebody-else', 'pveb1');

        $move = $this->move(new MoveTarget(node: $pveb1));

        $this->assertSame(ServerMoveStatus::Succeeded, $move->status, (string) $move->error);
        $this->assertNotSame($this->server->vmid, $move->target_vmid);
        $this->assertSame($move->target_vmid, $this->server->refresh()->vmid);
        $this->assertSame('somebody-else', $this->other->vms[$move->source_vmid]['config']['name']);
    }

    public function test_moves_to_other_clusters_need_the_switch_and_an_incoming_token()
    {
        $pveb1 = $this->otherCluster(incoming: false);

        $this->assertRefused(new MoveTarget(node: $pveb1), 'Moving servers to another cluster is turned off (PROXMOTION_CROSS_CLUSTER_MOVES).');

        config(['proxmotion.moves.cross_cluster' => true]);
        $this->assertRefused(new MoveTarget(node: $pveb1), 'The target cluster has no incoming move token, so servers cannot be moved into it.');
    }

    public function test_a_later_step_that_fails_after_the_vm_moved_can_be_finished()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $pveb1 = $this->otherCluster();
        $this->other->refusePool = 'Permission check failed (/pool/proxmotion, Pool.Allocate)';

        $move = $this->move(new MoveTarget(node: $pveb1));

        $this->assertSame(ServerMoveStatus::Failed, $move->status);
        $this->assertTrue($move->canFinish());
        // Recorded where Proxmox put it.
        $this->assertSame($pveb1->id, $this->server->refresh()->node_id);

        $this->other->refusePool = null;
        app(ServerMover::class)->finish($move, $this->admin, '127.0.0.1');

        $move->refresh();
        $this->assertSame(ServerMoveStatus::Succeeded, $move->status, (string) $move->error);
        $this->assertCount(1, $this->proxmox->remoteMigrations, 'Proxmox moves the VM only once.');
        $this->assertSame('proxmotion', $this->other->vms[$move->target_vmid]['pool']);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.move_resumed')->count());
    }

    public function test_a_server_moves_to_another_location_with_new_addresses()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $fra = Location::factory()->create(['code' => 'fra1']);
        $pool = IpPool::factory()->for($fra)->create(['network' => '198.51.100.0/29', 'gateway' => '198.51.100.1', 'nameservers' => ['192.0.2.54']]);
        app(GenerateIpAddresses::class)->handle($pool);
        $pvec1 = $this->otherCluster($fra);
        $old = $this->server->ipAddresses()->sole();
        $old->forceFill(['ptr' => 'web1.example.com'])->save();
        $vmid = $this->server->vmid;
        $ipconfig = $this->proxmox->vms[$vmid]['config']['ipconfig0'];

        $move = $this->move(new MoveTarget(node: $pvec1));

        $task = $move->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(ServerMoveKind::Location, $move->kind);
        $this->assertContains("POST /nodes/pve1/qemu/{$vmid}/status/shutdown", $this->proxmox->writePaths());
        $this->assertArrayNotHasKey('online', $this->proxmox->remoteMigrations[0], 'A move to another location copies a stopped VM.');

        $server = $this->server->refresh();
        $new = $server->ipAddresses()->sole();
        $this->assertSame($fra->id, $server->location_id);
        $this->assertStringStartsWith('198.51.100.', $new->address);
        $this->assertTrue($new->is_primary);
        $this->assertSame('web1.example.com', $new->ptr);
        $this->assertNull($old->refresh()->server_id);
        $this->assertNull($old->ptr);
        $this->assertSame(0, IpAddress::query()->whereNotNull('server_move_id')->count());

        $vm = $this->other->vms[$vmid];
        $this->assertSame('running', $vm['status']);
        $this->assertSame([$new->address], $vm['ipset']);
        $this->assertSame('192.0.2.54', $vm['config']['nameserver']);
        $this->assertSame($ipconfig, $vm['config']['ipconfig0'], 'ipconfig0 never changes (docs/adr/0011).');
        $this->assertStringContainsString($new->address, (string) $vm['config']['description']);
        $inputs = (string) ($this->other->guestFiles[$vmid]['/etc/proxmotion/addresses.json'] ?? '');
        $this->assertStringContainsString($new->address, $inputs);
        $this->assertSame(['check', 'shutdown', 'migrate', 'record', 'adopt', 'configure_network', 'firewall', 'start', 'wait_agent', 'guest_addresses', 'verify'], array_column($task->steps, 'key'));
    }

    public function test_a_failed_move_to_another_location_starts_the_server_again_and_frees_the_new_addresses()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $fra = Location::factory()->create(['code' => 'fra1']);
        app(GenerateIpAddresses::class)->handle(IpPool::factory()->for($fra)->create(['network' => '198.51.100.0/29', 'gateway' => '198.51.100.1']));
        $pvec1 = $this->otherCluster($fra);
        $this->proxmox->failMigration = 'disk import failed';
        $address = $this->server->ipAddresses()->sole()->address;

        $move = $this->move(new MoveTarget(node: $pvec1));

        $this->assertSame(ServerMoveStatus::Failed, $move->status);
        $this->assertSame('running', $this->proxmox->vms[$this->server->vmid]['status']);
        $this->assertSame(0, IpAddress::query()->whereNotNull('server_move_id')->count());
        $this->assertSame($address, $this->server->refresh()->ipAddresses()->sole()->address);
        $this->assertSame($this->location->id, $this->server->location_id);
    }

    public function test_the_server_page_offers_moves_and_asks_for_the_hostname_to_leave_the_cluster()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $pveb1 = $this->otherCluster();

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('moveOptions.refusal', null)
                ->where('moveOptions.cross_cluster', true)
                ->where('moveOptions.nodes', fn ($nodes) => collect($nodes)->pluck('label')->sort()->values()->all() === ['pve2 ('.$this->cluster->name.')', 'pveb1 ('.$pveb1->cluster->name.')'])
                ->has('moveOptions.moves', 0));

        $this->actingAs($this->admin)->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.move', $this->server), ['target' => 'node', 'node' => $pveb1->uuid, 'confirm_hostname' => 'wrong.example.com'])
            ->assertSessionHasErrors('confirm_hostname');

        $this->actingAs($this->admin)->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.move', $this->server), ['target' => 'node', 'node' => $pveb1->uuid, 'confirm_hostname' => 'web1.example.com'])
            ->assertRedirect(route('admin.servers.show', $this->server))
            ->assertSessionHasNoErrors();

        $this->assertSame($pveb1->id, $this->server->refresh()->node_id);
    }

    public function test_moving_within_the_cluster_needs_no_hostname_and_reports_refusals_on_the_form()
    {
        $pve2 = Node::query()->where('name', 'pve2')->sole();
        $pve2->forceFill(['max_servers' => 0])->save();

        $this->actingAs($this->admin)->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.move', $this->server), ['target' => 'node', 'node' => $pve2->uuid])
            ->assertSessionHasErrors(['move' => 'The chosen node does not have enough free CPU, memory or server slots for this server.']);
    }

    public function test_a_moving_server_holds_its_target_vmid()
    {
        config(['proxmotion.moves.cross_cluster' => true]);
        $pveb1 = $this->otherCluster();
        $move = new ServerMove;
        $move->forceFill([
            'server_id' => $this->server->id, 'kind' => ServerMoveKind::Cluster, 'status' => ServerMoveStatus::Running,
            'source_location_id' => $this->location->id, 'source_cluster_id' => $this->cluster->id, 'source_node_id' => $this->node->id, 'source_vmid' => 100,
            'target_location_id' => $this->location->id, 'target_cluster_id' => $pveb1->cluster_id, 'target_node_id' => $pveb1->id, 'target_vmid' => 123,
        ])->save();

        $this->assertSame([123], ServerMove::heldVmids($pveb1->cluster));
        $this->assertSame([], ServerMove::heldVmids($this->cluster));
    }
}
