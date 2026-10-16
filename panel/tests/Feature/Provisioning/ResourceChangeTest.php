<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Infrastructure\DriftDetector;
use App\Enums\DriftType;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\ServerDrift;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ResourceChange;
use App\Provisioning\ResourceChanger;
use App\Provisioning\ServerCreator;
use App\Provisioning\ServerSpec;
use App\Provisioning\TaskRunner;
use App\Proxmox\ProxmoxClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Upgrades and downgrades of a running server (ResourceChanger +
 * ChangeResourcesPipeline) on the in-memory Proxmox. The test queue is
 * synchronous, so request() runs the whole pipeline.
 */
class ResourceChangeTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        // 2 vCPU, 4 GiB, 40 GiB, 1 IPv4, 1000 Mbps (BuildsInfrastructure's package).
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->create();
    }

    private function change(int $cpu = 2, int $memory = 4096, int $disk = 40960, int $ipv4 = 1, int $ipv6 = 0, ?int $speed = 1000, bool $reboot = false): Task
    {
        $spec = new ServerSpec(cpuCores: $cpu, memoryMib: $memory, diskMib: $disk, ipv4Count: $ipv4, ipv6Count: $ipv6, portSpeedMbps: $speed);

        return app(ResourceChanger::class)->request($this->server, new ResourceChange($spec, $this->package, $reboot), $this->admin, '127.0.0.1')->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    public function test_an_upgrade_of_a_running_server_waits_for_its_restart()
    {
        $task = $this->change(cpu: 4, memory: 8192, disk: 61440, speed: 2000);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(TaskType::ChangeResources, $task->type);

        $server = $this->server->refresh();
        $this->assertSame([4, 8192, 61440, 2000], [$server->cpu_cores, $server->memory_mib, $server->disk_mib, $server->port_speed_mbps]);
        $this->assertTrue($server->reboot_required);

        // Cores and memory wait for the restart; disk and port speed apply now.
        $this->assertSame(['cores' => 4, 'memory' => 8192], array_map('intval', $this->proxmox->pending[$this->server->vmid]));
        $this->assertStringContainsString('size=61440M', (string) $this->vm()['config']['scsi0']);
        $this->assertStringContainsString('rate=250', (string) $this->vm()['config']['net0']);
        $this->assertSame('running', $this->vm()['status'], 'Not restarted without being asked.');
        $this->assertSame('The new CPU and memory take effect when the server is restarted.', collect($task->steps)->firstWhere('key', 'apply')['message'] ?? null);

        $log = AuditLog::query()->where('action', 'server.resources_changed')->sole();
        $this->assertSame(2, $log->properties['before']['cpu_cores'] ?? null);
        $this->assertSame(4, $log->properties['after']['cpu_cores'] ?? null);
    }

    public function test_an_upgrade_can_restart_the_server_right_away()
    {
        $task = $this->change(cpu: 4, memory: 8192, reboot: true);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertFalse($this->server->refresh()->reboot_required);
        $this->assertSame(4, (int) $this->vm()['config']['cores']);
        $this->assertArrayNotHasKey($this->server->vmid, $this->proxmox->pending);
        $this->assertContains('POST /nodes/pve1/qemu/'.$this->server->vmid.'/status/reboot', $this->proxmox->writePaths());
    }

    public function test_a_stopped_server_gets_the_change_at_once()
    {
        $this->proxmox->vms[$this->server->vmid]['status'] = 'stopped';

        $this->change(cpu: 4, memory: 8192);

        $this->assertFalse($this->server->refresh()->reboot_required);
        $this->assertSame(4, (int) $this->vm()['config']['cores']);
    }

    public function test_starting_or_restarting_the_server_clears_the_flag()
    {
        $this->change(cpu: 4);
        $this->assertTrue($this->server->refresh()->reboot_required);

        $task = app(TaskRunner::class)->start($this->server, TaskType::Reboot, $this->admin)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertFalse($this->server->refresh()->reboot_required);
        $this->assertSame(4, (int) $this->vm()['config']['cores']);
    }

    public function test_a_downgrade_keeps_the_disk()
    {
        $resizes = fn () => count(array_filter($this->proxmox->writePaths(), fn (string $path) => str_ends_with($path, '/resize')));
        $before = $resizes();

        $this->change(cpu: 1, memory: 2048, disk: 20480);

        $server = $this->server->refresh();
        $this->assertSame(1, $server->cpu_cores);
        $this->assertSame(2048, $server->memory_mib);
        $this->assertSame(40960, $server->disk_mib, 'Disks only grow.');
        $this->assertSame($before, $resizes(), 'The disk was resized.');
    }

    public function test_additional_addresses_come_from_the_pool_and_are_allowed_by_the_firewall()
    {
        $this->change(ipv4: 3);

        $addresses = $this->server->ipAddresses()->orderBy('id')->pluck('address')->all();
        $this->assertCount(3, $addresses);
        $this->assertSame($addresses, $this->vm()['ipset']);
    }

    public function test_fewer_addresses_release_the_newest_ones_and_never_the_primary()
    {
        $this->change(ipv4: 3);
        [$primary, $second, $third] = $this->server->ipAddresses()->orderBy('id')->get()->all();
        $third->forceFill(['ptr' => 'mail.example.com', 'ptr_synced_at' => now()])->save();

        $this->change(ipv4: 1);

        $this->assertSame([$primary->address], $this->server->ipAddresses()->pluck('address')->all());
        $this->assertSame([$primary->address], $this->vm()['ipset']);
        $this->assertNull($second->refresh()->server_id);
        $this->assertNull($third->refresh()->server_id);
        $this->assertNull($third->ptr, 'The PTR of a released address is removed.');

        // Zero still keeps the primary address.
        $this->change(ipv4: 0, ipv6: 0);
        $this->assertSame([$primary->address], $this->server->ipAddresses()->pluck('address')->all());
    }

    public function test_additional_addresses_are_configured_inside_the_guest()
    {
        $task = $this->change(ipv4: 3);
        $vmid = $this->server->vmid;
        [, $second, $third] = $this->server->ipAddresses()->orderBy('id')->pluck('address')->all();

        $files = $this->proxmox->guestFiles[$vmid];
        $inputs = json_decode($files['/etc/proxmotion/addresses.json'], true);
        $this->assertSame(["{$second}/29", "{$third}/29"], $inputs['addresses'], 'Only the additional ones, with the pool prefix.');
        $this->assertMatchesRegularExpression('/^bc:24:11(:[0-9a-f]{2}){3}$/', $inputs['mac']);
        $this->assertSame($this->server->uuid, $inputs['server']);
        $this->assertStringContainsString("mac={$inputs['mac']}\naddresses={$second}/29\naddresses={$third}/29\n", $files['/etc/proxmotion/addresses.vars']);
        $this->assertSame(file_get_contents(resource_path('guest/linux/addresses.sh')), $files['/usr/local/sbin/proxmotion-addresses']);
        $this->assertContains(['sh', '/usr/local/sbin/proxmotion-addresses'], array_column($this->proxmox->guestCommands, 'command'));
        $step = collect($task->steps)->firstWhere('key', 'guest_addresses');
        $this->assertNotNull($step, 'The guest step did not run.');
        $this->assertNull($step['message']);

        // A downgrade empties the list, so the script removes them inside the guest too.
        $this->change(ipv4: 1);
        $this->assertSame([], json_decode($this->proxmox->guestFiles[$vmid]['/etc/proxmotion/addresses.json'], true)['addresses']);
        $this->assertStringNotContainsString('addresses=', $this->proxmox->guestFiles[$vmid]['/etc/proxmotion/addresses.vars']);
    }

    public function test_routed_additional_addresses_are_slash_32()
    {
        $this->server->forceFill(['network_model' => 'routed'])->save();
        $this->ipv4Pool->forceFill(['network_model' => 'routed'])->save();

        $this->change(ipv4: 2);

        $this->assertStringEndsWith("/32\n", $this->proxmox->guestFiles[$this->server->vmid]['/etc/proxmotion/addresses.vars']);
    }

    public function test_without_a_guest_agent_the_change_still_succeeds_with_a_note()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $task = $this->change(ipv4: 2);

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(2, $this->server->ipAddresses()->count());
        $this->assertSame(
            'The guest agent did not answer: configure the additional addresses inside the server.',
            collect($task->steps)->firstWhere('key', 'guest_addresses')['message'] ?? null,
        );
    }

    public function test_a_failing_script_is_noted_but_does_not_fail_the_change()
    {
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', '/usr/local/sbin/proxmotion-addresses']
            ? [2, '', "No network interface with MAC address bc:24:11:00:00:01.\n"]
            : [0, '', ''];

        $task = $this->change(ipv4: 2);

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(
            'The additional addresses could not be configured inside the server: No network interface with MAC address bc:24:11:00:00:01.',
            collect($task->steps)->firstWhere('key', 'guest_addresses')['message'] ?? null,
        );
    }

    public function test_a_new_server_with_additional_addresses_gets_them_after_its_first_boot()
    {
        $server = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com', 'spec' => new ServerSpec(cpuCores: 1, memoryMib: 1024, diskMib: 10240, ipv4Count: 2, ipv6Count: 0)]))->refresh();

        $this->assertArrayHasKey('/etc/proxmotion/addresses.json', $this->proxmox->guestFiles[$server->vmid]);
        $this->assertCount(1, json_decode($this->proxmox->guestFiles[$server->vmid]['/etc/proxmotion/addresses.json'], true)['addresses']);

        // One address: nothing to do inside the guest.
        $this->assertArrayNotHasKey($this->server->vmid, $this->proxmox->guestFiles);
    }

    public function test_nothing_changes_when_the_node_is_full()
    {
        $this->node->forceFill(['memory_bytes' => 6 * 1024 ** 3, 'memory_overcommit' => 1])->save();

        try {
            $this->change(memory: 8192);
            $this->fail('The change was accepted.');
        } catch (ProvisioningException $e) {
            $this->assertSame("The server's node does not have enough free capacity for these resources.", $e->getMessage());
        }

        $this->assertSame(4096, $this->server->refresh()->memory_mib);
        $this->assertSame(0, Task::query()->where('type', TaskType::ChangeResources)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'server.resources_changed')->count());
    }

    public function test_nothing_changes_when_no_addresses_are_free()
    {
        IpAddress::query()->whereNull('server_id')->update(['is_reserved' => true]);

        try {
            $this->change(cpu: 4, ipv4: 2);
            $this->fail('The change was accepted.');
        } catch (ProvisioningException) {
        }

        $this->assertSame(2, $this->server->refresh()->cpu_cores);
        $this->assertSame(1, $this->server->ipAddresses()->count());
    }

    public function test_only_active_servers_and_one_task_at_a_time()
    {
        $running = new Task;
        $running->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();

        try {
            $this->change(cpu: 4);
            $this->fail('The change was accepted.');
        } catch (ProvisioningException $e) {
            $this->assertSame('Another task is still running for this server.', $e->getMessage());
        }

        $this->assertSame(2, $this->server->refresh()->cpu_cores, 'Rolled back.');
    }

    public function test_cores_and_memory_waiting_for_a_restart_are_not_drift()
    {
        $this->change(cpu: 4, memory: 8192);

        app(DriftDetector::class)->detect($this->cluster, app(ProxmoxClientFactory::class)->forCluster($this->cluster)->cluster()->guests());

        $this->assertSame(0, ServerDrift::query()->where('type', DriftType::ResourceMismatch)->count());
    }
}
