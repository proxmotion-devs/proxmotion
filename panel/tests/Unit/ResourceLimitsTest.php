<?php

namespace Tests\Unit;

use App\Provisioning\ResourceLimits;
use PHPUnit\Framework\TestCase;

/**
 * The VM settings of CPU and disk limits (docs/adr/0034).
 */
class ResourceLimitsTest extends TestCase
{
    private const CONFIG = [
        'boot' => 'order=scsi0;ide2;net0',
        'scsi0' => 'local-lvm:vm-100-disk-0,discard=on,size=40G',
        'ide2' => 'local-lvm:vm-100-cloudinit,media=cdrom',
    ];

    public function test_limits_become_cpu_settings_and_boot_disk_options()
    {
        $config = (new ResourceLimits(cpuLimit: 1.5, cpuUnits: 200, diskMbS: 100, diskIops: 2000))->proxmoxConfig(self::CONFIG);

        $this->assertSame([
            'cpulimit' => '1.5',
            'cpuunits' => 200,
            'scsi0' => 'local-lvm:vm-100-disk-0,discard=on,size=40G,mbps_rd=100,mbps_wr=100,iops_rd=2000,iops_wr=2000',
        ], $config['params']);
        $this->assertSame([], $config['delete']);
    }

    public function test_nothing_is_written_when_the_vm_already_has_them()
    {
        $current = [
            ...self::CONFIG,
            'cpulimit' => '2',
            'scsi0' => 'local-lvm:vm-100-disk-0,discard=on,iops_rd=500,iops_wr=500,size=40G',
        ];

        $config = (new ResourceLimits(cpuLimit: 2.0, diskIops: 500))->proxmoxConfig($current);

        $this->assertSame(['params' => [], 'delete' => []], $config);
    }

    public function test_unlimited_removes_earlier_limits()
    {
        $current = [
            ...self::CONFIG,
            'cpulimit' => '1',
            'cpuunits' => '50',
            'scsi0' => 'local-lvm:vm-100-disk-0,discard=on,mbps_rd=10,mbps_wr=10,size=40G',
        ];

        $config = (new ResourceLimits)->proxmoxConfig($current);

        $this->assertSame(['scsi0' => 'local-lvm:vm-100-disk-0,discard=on,size=40G'], $config['params']);
        $this->assertSame(['cpulimit', 'cpuunits'], $config['delete']);
    }

    public function test_a_vm_without_a_boot_disk_gets_only_cpu_settings()
    {
        $config = (new ResourceLimits(cpuUnits: 100, diskMbS: 50))->proxmoxConfig(['ide2' => 'none,media=cdrom']);

        $this->assertSame(['cpuunits' => 100], $config['params']);
    }
}
