<?php

namespace Tests\Unit;

use App\Domain\Infrastructure\StorageHealthCheck;
use App\Enums\StorageHealth;
use App\Proxmox\Data\CephStatus;
use App\Proxmox\Data\DiskInfo;
use App\Proxmox\Data\StorageConfig;
use PHPUnit\Framework\TestCase;

/**
 * How the health of storages and disks is judged (docs/adr/0047).
 */
class StorageHealthCheckTest extends TestCase
{
    private StorageHealthCheck $check;

    protected function setUp(): void
    {
        parent::setUp();

        $this->check = new StorageHealthCheck(85, 95);
    }

    /**
     * @param  array{0: StorageHealth, 1: list<array{severity: string, message: string, replace: array<string, string|int>}>}  $result
     * @return list<string>
     */
    private static function messages(array $result): array
    {
        return array_map(fn (array $problem) => $problem['severity'].': '.strtr($problem['message'], array_map('strval', array_combine(
            array_map(fn (string $key) => ":{$key}", array_keys($problem['replace'])),
            array_values($problem['replace']),
        ))), $result[1]);
    }

    public function test_an_available_storage_with_room_is_ok()
    {
        $result = $this->check->storage(true, 50, 100);

        $this->assertSame(StorageHealth::Ok, $result[0]);
        $this->assertSame([], $result[1]);
    }

    public function test_used_space_is_a_warning_then_critical()
    {
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 849, 1000)[0]);
        $this->assertSame(['warning: 85% of its space is used.'], self::messages($this->check->storage(true, 850, 1000)));
        $this->assertSame(['critical: 95% of its space is used.'], self::messages($this->check->storage(true, 950, 1000)));
        // Without a size nothing is judged.
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 10, null)[0]);
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 10, 0)[0]);
    }

    public function test_an_unavailable_storage_is_critical()
    {
        $this->assertSame(['critical: Proxmox reports this storage as not available.'], self::messages($this->check->storage(false, null, null)));
    }

    public function test_zfs_pool_states()
    {
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 1, 100, zfsState: 'ONLINE')[0]);
        $this->assertSame(['warning: Its ZFS pool is DEGRADED.'], self::messages($this->check->storage(true, 1, 100, zfsState: 'DEGRADED')));
        $this->assertSame(StorageHealth::Critical, $this->check->storage(true, 1, 100, zfsState: 'FAULTED')[0]);
        $this->assertSame(StorageHealth::Critical, $this->check->storage(true, 1, 100, zfsState: 'SUSPENDED')[0]);
    }

    public function test_thin_pool_metadata_counts_on_its_own()
    {
        $result = $this->check->storage(true, 10, 100, metadataUsedBytes: 96, metadataTotalBytes: 100);

        $this->assertSame(['critical: 96% of its thin pool metadata is used.'], self::messages($result));
        $this->assertSame(StorageHealth::Critical, $result[0]);
    }

    public function test_ceph_health()
    {
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 1, 100, cephHealth: 'HEALTH_OK')[0]);
        $this->assertSame(['warning: Ceph reports HEALTH_WARN.'], self::messages($this->check->storage(true, 1, 100, cephHealth: 'HEALTH_WARN')));
        $this->assertSame(StorageHealth::Critical, $this->check->storage(true, 1, 100, cephHealth: 'HEALTH_ERR')[0]);
    }

    public function test_the_worst_problem_wins()
    {
        $result = $this->check->storage(true, 90, 100, zfsState: 'FAULTED');

        $this->assertSame(StorageHealth::Critical, $result[0]);
        $this->assertCount(2, $result[1]);
    }

    public function test_a_level_reached_is_kept_until_the_value_drops_below_its_margin()
    {
        $this->assertSame(StorageHealth::Warning, $this->check->storage(true, 84, 100, previous: StorageHealth::Warning)[0]);
        $this->assertSame(StorageHealth::Warning, $this->check->storage(true, 83, 100, previous: StorageHealth::Warning)[0]);
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 82, 100, previous: StorageHealth::Warning)[0]);
        $this->assertSame(StorageHealth::Critical, $this->check->storage(true, 93, 100, previous: StorageHealth::Critical)[0]);
        $this->assertSame(StorageHealth::Warning, $this->check->storage(true, 92, 100, previous: StorageHealth::Critical)[0]);
        $this->assertSame(StorageHealth::Ok, $this->check->storage(true, 84, 100)[0]);
        $this->assertSame(StorageHealth::Warning, $this->check->disk('PASSED', 16, previous: StorageHealth::Warning)[0]);
    }

    public function test_disks()
    {
        $this->assertSame(StorageHealth::Ok, $this->check->disk('PASSED', 97)[0]);
        $this->assertSame(StorageHealth::Ok, $this->check->disk('OK', null)[0]);
        $this->assertSame(['critical: SMART reports the disk as failing.'], self::messages($this->check->disk('FAILED', null)));
        // Wearout is the rated life left, so 14 left is 86% used.
        $this->assertSame(['warning: 86% of its rated life is used.'], self::messages($this->check->disk('PASSED', 14)));
        $this->assertSame(StorageHealth::Critical, $this->check->disk('PASSED', 5)[0]);
        // RAID controllers and virtual disks have no SMART result.
        $this->assertSame(StorageHealth::Unknown, $this->check->disk('UNKNOWN', null)[0]);
        $this->assertSame(StorageHealth::Unknown, $this->check->disk(null, null)[0]);
        $this->assertSame(['warning: Proxmox no longer reports this disk.'], self::messages($this->check->disk('PASSED', 90, present: false)));
    }

    public function test_api_data_is_parsed()
    {
        $disk = DiskInfo::fromArray(['devpath' => '/dev/sda', 'health' => 'passed', 'wearout' => 'N/A', 'size' => '1000', 'model' => ' ']);
        $this->assertSame('PASSED', $disk->smart);
        $this->assertNull($disk->wearout);
        $this->assertNull($disk->model);
        $this->assertSame(1000, $disk->sizeBytes);

        $this->assertSame('rpool', StorageConfig::fromArray(['storage' => 'local-zfs', 'type' => 'zfspool', 'pool' => 'rpool/data'])->zpool());
        $this->assertTrue(StorageConfig::fromArray(['storage' => 'ceph', 'type' => 'rbd', 'pool' => 'vm'])->usesManagedCeph());
        $this->assertFalse(StorageConfig::fromArray(['storage' => 'ext', 'type' => 'rbd', 'pool' => 'vm', 'monhost' => '192.0.2.10'])->usesManagedCeph());

        $ceph = CephStatus::fromArray(['health' => ['status' => 'HEALTH_WARN', 'checks' => [
            'OSD_DOWN' => ['severity' => 'HEALTH_WARN', 'summary' => ['message' => '1 osds down', 'count' => 1]],
            'POOL_NO_REDUNDANCY' => ['severity' => 'HEALTH_WARN', 'summary' => ['message' => 'muted'], 'muted' => true],
        ]]]);
        $this->assertSame('HEALTH_WARN', $ceph->health);
        $this->assertSame([['severity' => 'HEALTH_WARN', 'message' => '1 osds down']], $ceph->checks);
    }
}
