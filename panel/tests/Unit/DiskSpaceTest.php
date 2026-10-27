<?php

namespace Tests\Unit;

use App\Domain\Usage\DiskSpace;
use App\Proxmox\Data\GuestFileSystem;
use PHPUnit\Framework\TestCase;

/**
 * Which file systems of get-fsinfo the server page shows (docs/adr/0036 §7).
 */
class DiskSpaceTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $result
     * @return list<string>
     */
    private function mountpoints(array $result): array
    {
        return array_column(DiskSpace::summarize(array_map(GuestFileSystem::fromApi(...), $result)), 'mountpoint');
    }

    public function test_the_root_file_system_comes_first_then_by_mount_point()
    {
        $this->assertSame(['/', '/boot', '/var'], $this->mountpoints([
            ['name' => 'dm-1', 'mountpoint' => '/var', 'type' => 'xfs', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'sda1', 'mountpoint' => '/boot', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'dm-0', 'mountpoint' => '/', 'type' => 'xfs', 'used-bytes' => 1, 'total-bytes' => 10],
        ]));
    }

    public function test_windows_lists_c_first_and_skips_volumes_without_a_drive_letter()
    {
        $this->assertSame(['C:\\', 'D:\\'], $this->mountpoints([
            ['name' => '\\\\?\\Volume{1}\\', 'mountpoint' => 'D:\\', 'type' => 'NTFS', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => '\\\\?\\Volume{2}\\', 'mountpoint' => '\\\\?\\Volume{2}\\', 'type' => 'NTFS', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => '\\\\?\\Volume{3}\\', 'mountpoint' => 'C:\\', 'type' => 'NTFS', 'used-bytes' => 1, 'total-bytes' => 10],
            // Cloudbase-Init's configuration drive.
            ['name' => '\\\\?\\Volume{4}\\', 'mountpoint' => 'E:\\', 'type' => 'CDFS', 'used-bytes' => 10, 'total-bytes' => 10],
        ]));
    }

    public function test_read_only_images_and_file_systems_without_sizes_are_skipped()
    {
        $this->assertSame(['/'], $this->mountpoints([
            ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'loop0', 'mountpoint' => '/snap/core22/1234', 'type' => 'squashfs', 'used-bytes' => 10, 'total-bytes' => 10],
            ['name' => 'sr0', 'mountpoint' => '/mnt/cidata', 'type' => 'iso9660', 'used-bytes' => 10, 'total-bytes' => 10],
            // An agent too old to report sizes.
            ['name' => 'sdb1', 'mountpoint' => '/data', 'type' => 'ext4'],
            ['name' => 'sdc1', 'mountpoint' => '/empty', 'type' => 'ext4', 'used-bytes' => 0, 'total-bytes' => 0],
        ]));
    }

    public function test_a_device_mounted_several_times_is_listed_once_by_its_shortest_mount_point()
    {
        $this->assertSame(['/', '/srv'], $this->mountpoints([
            ['name' => 'sda1', 'mountpoint' => '/var/lib/docker/overlay', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'sdb1', 'mountpoint' => '/srv', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
        ]));
    }

    public function test_a_path_mounted_over_is_listed_once_with_what_is_mounted_last()
    {
        $fileSystems = DiskSpace::summarize(array_map(GuestFileSystem::fromApi(...), [
            ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'sdb1', 'mountpoint' => '/mnt', 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10],
            ['name' => 'sdc1', 'mountpoint' => '/mnt', 'type' => 'xfs', 'used-bytes' => 2, 'total-bytes' => 20],
        ]));

        $this->assertSame(['/', '/mnt'], array_column($fileSystems, 'mountpoint'));
        $this->assertSame('xfs', $fileSystems[1]['type']);
    }

    public function test_at_most_ten_file_systems_are_listed()
    {
        $result = array_map(fn (int $i) => ['name' => "sd{$i}", 'mountpoint' => "/mnt/{$i}", 'type' => 'ext4', 'used-bytes' => 1, 'total-bytes' => 10], range(1, 15));

        $this->assertCount(DiskSpace::MAX_FILE_SYSTEMS, $this->mountpoints($result));
    }
}
