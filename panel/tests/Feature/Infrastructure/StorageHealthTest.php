<?php

namespace Tests\Feature\Infrastructure;

use App\Domain\Infrastructure\ClusterSynchronizer;
use App\Domain\Infrastructure\StorageHealthCollector;
use App\Enums\NotificationEvent;
use App\Enums\StorageHealth;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\User;
use App\Notifications\AdminAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesProxmox;
use Tests\TestCase;

/**
 * Storage health read by the cluster sync (docs/adr/0047).
 */
class StorageHealthTest extends TestCase
{
    use FakesProxmox, RefreshDatabase;

    private Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'proxmotion.notifications.enabled' => true,
            'proxmotion.notifications.recipients' => [],
            'proxmotion.notifications.throttle_minutes' => 60,
        ]);
        Notification::fake();

        $this->cluster = Cluster::factory()->create(['name' => 'dc1']);
    }

    private function sync(): void
    {
        app(ClusterSynchronizer::class)->sync($this->cluster);
        $this->cluster->refresh();
    }

    private function pve1(): Node
    {
        return $this->cluster->nodes()->where('name', 'pve1')->sole();
    }

    public function test_the_sync_reads_disks_pools_and_ceph()
    {
        $this->fakeProxmox();

        $this->sync();

        $pve1 = $this->pve1();
        $this->assertNull($pve1->disk_health_error);
        $this->assertSame('HEALTH_OK', $this->cluster->ceph_health);
        $this->assertSame([], $this->cluster->ceph_checks);

        $nvme = $pve1->disks()->where('devpath', '/dev/nvme0n1')->sole();
        $this->assertSame('PASSED', $nvme->smart);
        $this->assertSame(97, $nvme->wearout);
        $this->assertSame(StorageHealth::Ok, $nvme->health);
        $this->assertNull($pve1->disks()->where('devpath', '/dev/sda')->sole()->wearout);

        $thin = $pve1->storages()->where('name', 'local-lvm')->sole();
        $this->assertSame(StorageHealth::Ok, $thin->health);
        $this->assertSame(107374182, $thin->metadata_used_bytes);
        $this->assertSame(1073741824, $thin->metadata_total_bytes);
        $this->assertSame('HEALTH_OK', $pve1->storages()->where('name', 'ceph-vm')->sole()->pool_state);

        // The offline node is not asked.
        $this->assertSame(0, $this->cluster->nodes()->where('name', 'pve2')->sole()->disks()->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/nodes/pve2/disks'));

        $this->assertSame(StorageHealth::Ok, $this->cluster->storageHealth());
        Notification::assertNothingSent();
    }

    public function test_problems_are_recorded()
    {
        $this->fakeProxmox([
            '*/api2/json/storage' => Http::response(['data' => [
                ['storage' => 'local-lvm', 'type' => 'zfspool', 'pool' => 'rpool/data'],
                ['storage' => 'ceph-vm', 'type' => 'rbd', 'pool' => 'vm'],
            ]]),
            '*/api2/json/nodes/*/disks/zfs' => Http::response(['data' => [['name' => 'rpool', 'health' => 'DEGRADED']]]),
            '*/api2/json/nodes/*/disks/list' => Http::response(['data' => [
                ['devpath' => '/dev/sda', 'type' => 'ssd', 'model' => 'Example SSD', 'health' => 'FAILED', 'wearout' => 40],
            ]]),
            '*/api2/json/cluster/ceph/status' => Http::response(['data' => ['health' => ['status' => 'HEALTH_ERR', 'checks' => [
                'PG_DAMAGED' => ['severity' => 'HEALTH_ERR', 'summary' => ['message' => 'Possible data damage: 1 pg inconsistent']],
            ]]]]),
        ]);

        $this->sync();

        $pve1 = $this->pve1();
        $zfs = $pve1->storages()->where('name', 'local-lvm')->sole();
        $this->assertSame(StorageHealth::Warning, $zfs->health);
        $this->assertSame('DEGRADED', $zfs->pool_state);
        $this->assertSame([['severity' => 'warning', 'message' => 'Its ZFS pool is :state.', 'replace' => ['state' => 'DEGRADED']]], $zfs->health_problems);
        $this->assertSame(StorageHealth::Critical, $pve1->storages()->where('name', 'ceph-vm')->sole()->health);
        $this->assertSame(StorageHealth::Critical, $pve1->disks()->sole()->health);
        $this->assertSame([['severity' => 'HEALTH_ERR', 'message' => 'Possible data damage: 1 pg inconsistent']], $this->cluster->ceph_checks);
        $this->assertSame(StorageHealth::Critical, $this->cluster->storageHealth());
    }

    public function test_administrators_get_one_mail_when_health_gets_worse()
    {
        $admin = User::factory()->create(['locale' => 'en']);
        $this->fakeProxmox([
            '*/api2/json/nodes/*/disks/list' => Http::response(['data' => [
                ['devpath' => '/dev/sda', 'type' => 'ssd', 'model' => 'Example SSD', 'health' => 'FAILED', 'wearout' => 40],
            ]]),
        ]);

        $this->sync();

        Notification::assertSentTo($admin, AdminAlert::class, fn (AdminAlert $alert) => $alert->event === NotificationEvent::StorageUnhealthy
            && in_array(['Disk :disk (:model) on node :node:', ['disk' => '/dev/sda', 'model' => 'Example SSD', 'node' => 'pve1']], $alert->lines, true)
            && in_array(['SMART reports the disk as failing.', []], $alert->lines, true));

        // Nothing new: no further mail, even after the throttle window.
        $this->travel(2)->hours();
        $this->sync();
        Notification::assertSentToTimes($admin, AdminAlert::class, 1);
    }

    public function test_a_later_problem_is_mailed_even_shortly_after_another()
    {
        $admin = User::factory()->create(['locale' => 'en']);
        $smart = 'PASSED';
        $zfs = 'DEGRADED';
        $this->fakeProxmox([
            '*/api2/json/storage' => Http::response(['data' => [['storage' => 'local-lvm', 'type' => 'zfspool', 'pool' => 'rpool/data']]]),
            '*/api2/json/nodes/*/disks/zfs' => function () use (&$zfs) {
                return Http::response(['data' => [['name' => 'rpool', 'health' => $zfs]]]);
            },
            '*/api2/json/nodes/*/disks/list' => function () use (&$smart) {
                return Http::response(['data' => [['devpath' => '/dev/sda', 'serial' => 'S1', 'health' => $smart]]]);
            },
        ]);

        $this->sync();
        Notification::assertSentToTimes($admin, AdminAlert::class, 1);

        // Within the throttle window of other events, after the disk interval.
        $smart = 'FAILED';
        $this->travel(StorageHealthCollector::DISK_INTERVAL_MINUTES + 1)->minutes();
        $this->sync();

        Notification::assertSentToTimes($admin, AdminAlert::class, 2);
    }

    public function test_what_cannot_be_read_keeps_its_last_known_state()
    {
        $admin = User::factory()->create(['locale' => 'en']);
        $fail = false;
        $failing = fn (array $data) => function () use (&$fail, $data) {
            return $fail ? Http::response(['data' => null], 500) : Http::response(['data' => $data]);
        };
        $this->fakeProxmox([
            '*/api2/json/storage' => $failing([
                ['storage' => 'local-lvm', 'type' => 'zfspool', 'pool' => 'rpool/data'],
                ['storage' => 'ceph-vm', 'type' => 'rbd', 'pool' => 'vm'],
            ]),
            '*/api2/json/nodes/*/disks/zfs' => $failing([['name' => 'rpool', 'health' => 'DEGRADED']]),
            '*/api2/json/cluster/ceph/status' => $failing(['health' => ['status' => 'HEALTH_WARN', 'checks' => []]]),
        ]);

        $this->sync();
        Notification::assertSentToTimes($admin, AdminAlert::class, 1);

        // The configuration, the pools and Ceph all fail once, then answer again.
        $fail = true;
        $this->sync();
        $this->assertSame('HEALTH_WARN', $this->cluster->ceph_health);
        $this->assertSame('DEGRADED', $this->pve1()->storages()->where('name', 'local-lvm')->sole()->pool_state);
        $this->assertSame(StorageHealth::Warning, $this->pve1()->storages()->where('name', 'local-lvm')->sole()->health);
        $this->assertSame(StorageHealth::Warning, $this->pve1()->storages()->where('name', 'ceph-vm')->sole()->health);

        $fail = false;
        $this->sync();
        Notification::assertSentToTimes($admin, AdminAlert::class, 1);
    }

    public function test_disks_are_matched_by_serial_and_read_at_most_every_interval()
    {
        $disks = [
            ['devpath' => '/dev/sda', 'serial' => 'A', 'health' => 'PASSED'],
            ['devpath' => '/dev/sdb', 'serial' => 'B', 'health' => 'PASSED'],
            ['devpath' => '/dev/sdc', 'serial' => 'C', 'health' => 'PASSED'],
        ];
        $this->fakeProxmox(['*/api2/json/nodes/*/disks/list' => function () use (&$disks) {
            return Http::response(['data' => $disks]);
        }]);
        $this->sync();
        $this->sync();
        // smartctl runs on every disk: the second sync within the interval does not ask.
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/nodes/pve1/disks/list')));

        // B was pulled; after a reboot C is sdb.
        $disks = [
            ['devpath' => '/dev/sda', 'serial' => 'A', 'health' => 'PASSED'],
            ['devpath' => '/dev/sdb', 'serial' => 'C', 'health' => 'PASSED'],
        ];
        $this->travel(StorageHealthCollector::DISK_INTERVAL_MINUTES + 1)->minutes();
        $this->sync();

        $gone = $this->pve1()->disks()->where('is_present', false)->sole();
        $this->assertSame('B', $gone->serial);
        $this->assertSame('/dev/sdb', $this->pve1()->disks()->where('serial', 'C')->sole()->devpath);
    }

    public function test_a_sync_survives_unexpected_answers()
    {
        $this->fakeProxmox(['*/api2/json/nodes/*/disks/lvmthin' => Http::response(['data' => [['vg' => 'pve']]])]);

        $this->sync();

        $this->assertNull($this->cluster->last_sync_error);
    }

    public function test_a_shared_storage_is_mailed_once()
    {
        $admin = User::factory()->create(['locale' => 'en']);
        $this->fakeProxmox([
            '*/api2/json/cluster/status' => $this->proxmoxFixture('cluster-status'),
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online', 'maxcpu' => 8, 'maxmem' => 1024],
                ['node' => 'pve2', 'status' => 'online', 'maxcpu' => 8, 'maxmem' => 1024],
            ]]),
            '*/api2/json/cluster/resources?type=storage' => Http::response(['data' => [
                ['storage' => 'ceph-vm', 'node' => 'pve1', 'plugintype' => 'rbd', 'content' => 'images', 'shared' => 1, 'status' => 'available', 'disk' => 1, 'maxdisk' => 100],
                ['storage' => 'ceph-vm', 'node' => 'pve2', 'plugintype' => 'rbd', 'content' => 'images', 'shared' => 1, 'status' => 'available', 'disk' => 1, 'maxdisk' => 100],
            ]]),
            '*/api2/json/cluster/ceph/status' => Http::response(['data' => ['health' => ['status' => 'HEALTH_WARN', 'checks' => []]]]),
        ]);

        $this->sync();

        Notification::assertSentTo($admin, AdminAlert::class, function (AdminAlert $alert) {
            $titles = array_filter($alert->lines, fn (array $line) => $line[0] === 'Storage :storage on node :node:');

            return count($titles) === 1;
        });
    }

    public function test_unreadable_disks_never_fail_the_sync()
    {
        $this->fakeProxmox([
            '*/api2/json/nodes/*/disks/list' => Http::response(['data' => null, 'message' => 'Permission check failed (/nodes/pve1, Sys.Audit)'], 403),
            '*/api2/json/storage' => Http::response(['data' => null], 500),
        ]);

        $this->sync();

        $this->assertNull($this->cluster->last_sync_error);
        $this->assertNotNull($this->pve1()->disk_health_error);
        // Usage is still judged without the pools.
        $this->assertSame(StorageHealth::Ok, $this->pve1()->storages()->where('name', 'local-lvm')->sole()->health);
        $this->assertNull($this->cluster->ceph_health);
    }

    public function test_a_disk_that_vanished_stays_until_forgotten()
    {
        $disks = [
            ['devpath' => '/dev/nvme0n1', 'type' => 'nvme', 'health' => 'PASSED', 'wearout' => 97],
            ['devpath' => '/dev/sda', 'type' => 'hdd', 'health' => 'PASSED'],
        ];
        $this->fakeProxmox(['*/api2/json/nodes/*/disks/list' => function () use (&$disks) {
            return Http::response(['data' => $disks]);
        }]);
        $this->sync();

        array_pop($disks);
        $this->travel(StorageHealthCollector::DISK_INTERVAL_MINUTES + 1)->minutes();
        $this->sync();

        $sda = $this->pve1()->disks()->where('devpath', '/dev/sda')->sole();
        $this->assertFalse($sda->is_present);
        $this->assertSame(StorageHealth::Warning, $sda->health);

        $admin = User::factory()->withTwoFactor()->create();
        $present = $this->pve1()->disks()->where('devpath', '/dev/nvme0n1')->sole();

        $this->actingAs($admin)->delete(route('admin.node-disks.destroy', $present))->assertStatus(409);
        $this->actingAs($admin)->delete(route('admin.node-disks.destroy', $sda))->assertRedirect();

        $this->assertModelMissing($sda);
        $this->assertModelExists($present);
        $this->assertTrue(AuditLog::query()->where('action', 'node_disk.forgotten')->where('properties->devpath', '/dev/sda')->exists());
    }

    public function test_the_cluster_page_shows_the_health()
    {
        $this->fakeProxmox(['*/api2/json/nodes/*/disks/list' => Http::response(['data' => [
            ['devpath' => '/dev/sda', 'type' => 'ssd', 'model' => 'Example SSD', 'health' => 'PASSED', 'wearout' => 10],
        ]])]);
        $this->sync();

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('admin.clusters.show', $this->cluster))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cluster.storage_health', 'warning')
                ->where('cluster.ceph_health', 'HEALTH_OK')
                ->where('nodes.0.name', 'pve1')
                ->where('nodes.0.disks.0.devpath', '/dev/sda')
                ->where('nodes.0.disks.0.health', 'warning')
                ->where('nodes.0.disks.0.health_problems.0.message', ':percent% of its rated life is used.')
                ->where('nodes.0.storages.1.name', 'local')
                ->where('nodes.0.storages.1.health', 'ok'));

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('admin.clusters.index'))
            ->assertInertia(fn (Assert $page) => $page->where('clusters.0.storage_health', 'warning'));

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.storage_problems', 1));
    }

    public function test_shared_storages_count_once_and_nodes_not_online_not_at_all()
    {
        $this->fakeProxmox([
            '*/api2/json/nodes' => Http::response(['data' => [
                ['node' => 'pve1', 'status' => 'online', 'maxcpu' => 8, 'maxmem' => 1024],
                ['node' => 'pve2', 'status' => 'online', 'maxcpu' => 8, 'maxmem' => 1024],
            ]]),
            '*/api2/json/cluster/resources?type=storage' => Http::response(['data' => [
                ['storage' => 'ceph-vm', 'node' => 'pve1', 'plugintype' => 'rbd', 'content' => 'images', 'shared' => 1, 'status' => 'available', 'disk' => 1, 'maxdisk' => 100],
                ['storage' => 'ceph-vm', 'node' => 'pve2', 'plugintype' => 'rbd', 'content' => 'images', 'shared' => 1, 'status' => 'available', 'disk' => 1, 'maxdisk' => 100],
            ]]),
            '*/api2/json/cluster/ceph/status' => Http::response(['data' => ['health' => ['status' => 'HEALTH_WARN', 'checks' => []]]]),
        ]);
        $this->sync();

        // A node removed from the cluster keeps its rows, but no longer counts.
        $removed = Node::factory()->for($this->cluster)->create(['name' => 'pve3', 'status' => 'unknown']);
        $removed->storages()->make()->forceFill(['name' => 'local', 'type' => 'dir', 'content' => [], 'is_shared' => false, 'is_available' => false, 'health' => 'critical'])->save();

        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('stats.storage_problems', 1));
        $this->actingAs($admin)
            ->get(route('admin.clusters.index'))
            ->assertInertia(fn (Assert $page) => $page->where('clusters.0.storage_health', 'warning'));
    }
}
