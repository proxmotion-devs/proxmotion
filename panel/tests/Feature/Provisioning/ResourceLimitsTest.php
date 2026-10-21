<?php

namespace Tests\Feature\Provisioning;

use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ResourceLimits;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * CPU and disk limits from the package or the admin forms, written to the VM
 * on create and on resource changes (docs/adr/0034).
 */
class ResourceLimitsTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Server $server): array
    {
        return $this->proxmox->vms[$server->vmid]['config'];
    }

    public function test_a_server_gets_the_limits_of_its_package()
    {
        $this->package->update(['cpu_limit' => 1.5, 'cpu_units' => 200, 'disk_mb_s' => 100, 'disk_iops' => 2000]);

        $server = app(ServerCreator::class)->create($this->order(['spec' => ServerSpec::fromPackage($this->package->refresh())]));

        $this->assertSame(['1.50', 200, 100, 2000], [$server->cpu_limit, $server->cpu_units, $server->disk_mb_s, $server->disk_iops]);
        $config = $this->config($server);
        $this->assertSame('1.5', $config['cpulimit']);
        $this->assertSame(200, (int) $config['cpuunits']);
        $this->assertStringContainsString('mbps_rd=100,mbps_wr=100,iops_rd=2000,iops_wr=2000', $config['scsi0']);
        $this->assertStringContainsString('size=40960M', $config['scsi0'], 'The disk still grew.');
    }

    public function test_a_server_without_limits_gets_none()
    {
        $server = app(ServerCreator::class)->create($this->order());

        $this->assertArrayNotHasKey('cpulimit', $this->config($server));
        $this->assertStringNotContainsString('mbps_rd', $this->config($server)['scsi0']);
    }

    public function test_changing_resources_applies_and_removes_limits()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        $spec = fn (ResourceLimits $limits) => new ServerSpec(cpuCores: 2, memoryMib: 4096, diskMib: 40960, ipv4Count: 1, ipv6Count: 0, portSpeedMbps: 1000, limits: $limits);

        $task = app(ResourceChanger::class)->request($server, new ResourceChange($spec(new ResourceLimits(cpuLimit: 1.0, diskMbS: 50)), null, false), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('1', $this->config($server)['cpulimit']);
        $this->assertStringContainsString('mbps_rd=50,mbps_wr=50', $this->config($server)['scsi0']);

        $task = app(ResourceChanger::class)->request($server->refresh(), new ResourceChange($spec(new ResourceLimits), null, false), $this->admin, null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertArrayNotHasKey('cpulimit', $this->config($server));
        $this->assertStringNotContainsString('mbps_rd', $this->config($server)['scsi0']);
        $this->assertNull($server->refresh()->cpu_limit);
    }

    public function test_the_admin_dialog_takes_limits_and_validates_them()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $values = ['cpu_cores' => 2, 'memory_mib' => 4096, 'disk_gib' => 40, 'ipv4_count' => 1, 'ipv6_count' => 0];

        $this->actingAs($this->admin)->post(route('admin.servers.resources', $server), [...$values, 'cpu_limit' => 0])
            ->assertSessionHasErrors('cpu_limit');
        $this->actingAs($this->admin)->post(route('admin.servers.resources', $server), [...$values, 'cpu_units' => 20000])
            ->assertSessionHasErrors('cpu_units');

        $this->actingAs($this->admin)->post(route('admin.servers.resources', $server), [...$values, 'cpu_limit' => 0.5, 'disk_iops' => 300])
            ->assertSessionHasNoErrors();

        $this->assertSame(['0.50', 300], [$server->refresh()->cpu_limit, $server->disk_iops]);
        $this->assertSame('0.5', $this->config($server)['cpulimit']);
    }
}
