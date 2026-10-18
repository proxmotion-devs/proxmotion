<?php

namespace Tests\Feature\Provisioning;

use App\Enums\NetworkModel;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\IpAddress;
use App\Models\Server;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * End-to-end provisioning against an in-memory Proxmox. The queue runs
 * synchronously in tests, so the whole pipeline runs inside create().
 */
class ServerProvisioningTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    public function test_a_server_is_cloned_configured_resized_protected_and_started()
    {
        $server = app(ServerCreator::class)->create($this->order(['sshKeys' => 'ssh-ed25519 AAAAC3Nza test@example']));

        $server->refresh();
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertNotNull($server->provisioned_at);
        $this->assertSame(100, $server->vmid);
        $this->assertSame($this->node->id, $server->node_id);
        $this->assertSame($this->storage->id, $server->storage_id);

        $vm = $this->proxmox->vms[100];
        $this->assertSame('proxmotion', $vm['pool']);
        $this->assertSame('running', $vm['status']);
        $this->assertSame('web1.example.com', $vm['config']['name']);
        $this->assertEquals(2, $vm['config']['cores']);
        $this->assertEquals(4096, $vm['config']['memory']);
        $this->assertSame('root', $vm['config']['ciuser']);
        $this->assertSame('a-very-long-password', $vm['config']['cipassword']);
        $this->assertSame(rawurlencode('ssh-ed25519 AAAAC3Nza test@example'), $vm['config']['sshkeys']);
        $this->assertSame('ip=203.0.113.2/29,gw=203.0.113.1', $vm['config']['ipconfig0']);
        $this->assertSame('192.0.2.53', $vm['config']['nameserver']);
        $this->assertStringContainsString('bridge=vmbr0,firewall=1,rate=125', $vm['config']['net0']);
        $this->assertStringContainsString('size=40960M', $vm['config']['scsi0']);
        $this->assertSame(['203.0.113.2'], $vm['ipset']);
        $this->assertEquals(1, $vm['firewall']['ipfilter']);

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent'], array_column($task->steps, 'key'));
    }

    public function test_a_server_becomes_active_only_once_its_guest_agent_answers()
    {
        $this->proxmox->agentDown[100] = true;
        $statuses = [];
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function () use (&$statuses) {
            $statuses[] = Server::query()->sole()->status;
            // The guest finishes its first boot after a while.
            $this->proxmox->agentDown[100] = count($statuses) < 3;
        });

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame([ServerStatus::Provisioning, ServerStatus::Provisioning, ServerStatus::Provisioning], $statuses);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $step = collect($server->tasks()->sole()->steps)->firstWhere('key', 'wait_agent');
        $this->assertSame('done', $step['status']);
        $this->assertNull($step['message']);
    }

    public function test_a_server_whose_agent_never_answers_is_active_with_a_note()
    {
        $this->proxmox->agentDown[100] = true;
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame('The guest agent did not answer within 10 minutes.', collect($task->steps)->firstWhere('key', 'wait_agent')['message']);
    }

    public function test_concurrent_orders_get_different_vmids_and_addresses()
    {
        $first = app(ServerCreator::class)->create($this->order(['hostname' => 'a.example.com']));
        $second = app(ServerCreator::class)->create($this->order(['hostname' => 'b.example.com']));

        $this->assertNotSame($first->vmid, $second->vmid);
        $this->assertNotEquals(
            $first->ipAddresses()->pluck('address')->all(),
            $second->ipAddresses()->pluck('address')->all(),
        );
    }

    public function test_a_node_ip_in_the_pool_is_never_assigned_even_if_unreserved()
    {
        // The node itself uses .2 — the first free address of the pool.
        $this->node->bridges()->where('name', 'vmbr0')->update(['cidr' => '203.0.113.2/29']);

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(['203.0.113.3'], $server->ipAddresses()->pluck('address')->all());
    }

    public function test_vmids_used_outside_the_panel_are_skipped()
    {
        $this->proxmox->addVm(100, 'existing-vm', pool: null);

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(101, $server->vmid);
    }

    public function test_vmids_can_start_at_a_configured_minimum()
    {
        config(['proxmotion.provisioning.min_vmid' => 5000]);
        $this->proxmox->addVm(5000, 'existing-vm', pool: null);

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(5001, $server->vmid);
    }

    public function test_running_out_of_addresses_reserves_nothing()
    {
        IpAddress::query()->update(['is_reserved' => true]);

        try {
            app(ServerCreator::class)->create($this->order());
            $this->fail('Expected a provisioning exception.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('No free :version addresses', $e->getMessage());
        }

        $this->assertDatabaseCount('servers', 0);
        $this->assertSame([], $this->proxmox->writes);
    }

    public function test_a_failed_clone_marks_the_server_and_a_retry_continues()
    {
        $this->proxmox->failClone = 'clone failed: storage full';

        $server = app(ServerCreator::class)->create($this->order());

        $server->refresh();
        $this->assertSame(ServerStatus::Error, $server->status);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertStringContainsString('storage full', (string) $task->error);
        $this->assertCount(1, $server->ipAddresses, 'Addresses stay reserved for the retry.');

        // Proxmox did create the VM before failing; the retry must not clone again.
        $this->proxmox->failClone = null;
        app(TaskRunner::class)->start($server, TaskType::Create);

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $clones = array_filter($this->proxmox->writePaths(), fn ($write) => str_ends_with($write, '/clone'));
        $this->assertCount(1, $clones);
        $this->assertSame('VM already exists.', $server->tasks()->latest('id')->first()->steps[0]['message']);
    }

    public function test_rerunning_configuration_keeps_the_mac_address()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $mac = $this->proxmox->vms[$server->vmid]['config']['net0'];

        $server->forceFill(['status' => ServerStatus::Error])->save();
        app(TaskRunner::class)->start($server, TaskType::Create);

        $this->assertSame($mac, $this->proxmox->vms[$server->vmid]['config']['net0']);
    }

    public function test_the_mac_address_proxmox_generated_is_recorded()
    {
        $server = app(ServerCreator::class)->create($this->order());

        preg_match('/^virtio=([0-9A-F:]{17}),/i', $this->proxmox->vms[$server->vmid]['config']['net0'], $match);
        $this->assertNotEmpty($match);
        $this->assertSame(strtolower($match[1]), $server->refresh()->mac_address);
    }

    public function test_a_failed_save_of_the_server_still_ends_the_task_and_marks_the_server()
    {
        // E.g. a column the database does not have yet: every save carrying it fails.
        Server::saving(function (Server $server) {
            if ($server->isDirty('mac_address')) {
                throw new \RuntimeException('no such column: mac_address');
            }
        });

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(ServerStatus::Error, $server->refresh()->status);
        $this->assertNull($server->mac_address);
        $this->assertSame(TaskStatus::Failed, $server->tasks()->latest('id')->first()->status);
    }

    public function test_routed_ips_use_a_32_with_the_provider_mac()
    {
        $this->ipv4Pool->update(['network_model' => NetworkModel::Routed, 'gateway' => '198.51.100.254']);
        IpAddress::query()->update(['mac_address' => '02:00:00:aa:bb:cc']);

        $server = app(ServerCreator::class)->create($this->order(['networkModel' => NetworkModel::Routed]));

        $config = $this->proxmox->vms[$server->vmid]['config'];
        $this->assertSame('ip=203.0.113.2/32,gw=198.51.100.254', $config['ipconfig0']);
        $this->assertStringStartsWith('virtio=02:00:00:aa:bb:cc,', $config['net0']);
        $this->assertSame('02:00:00:aa:bb:cc', $server->refresh()->mac_address);
    }

    public function test_ipv6_addresses_are_allocated_from_the_pool()
    {
        $this->addIpv6Pool();
        $this->package->update(['ipv6_count' => 1]);

        $server = app(ServerCreator::class)->create($this->order());
        $second = app(ServerCreator::class)->create($this->order(['hostname' => 'web2.example.com']));

        $this->assertStringEndsWith(',ip6=2001:db8:100::2/64,gw6=2001:db8:100::1', $this->proxmox->vms[$server->vmid]['config']['ipconfig0']);
        $this->assertContains('2001:db8:100::3', $second->ipAddresses()->pluck('address')->all());
    }

    public function test_ipv6_is_skipped_when_the_location_has_no_ipv6_pool()
    {
        $this->package->update(['ipv6_count' => 1]);

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertSame(['203.0.113.2'], $server->ipAddresses()->pluck('address')->all());
        $this->assertStringNotContainsString('ip6=', $this->proxmox->vms[$server->vmid]['config']['ipconfig0']);
    }

    public function test_an_ipv6_only_server_needs_an_ipv6_pool()
    {
        $this->package->update(['ipv4_count' => 0, 'ipv6_count' => 1]);

        try {
            app(ServerCreator::class)->create($this->order());
            $this->fail('An IPv6-only server was created without an IPv6 pool.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('IPv6', $e->translatedMessage());
        }

        $this->assertSame(0, Server::query()->count());
    }

    public function test_nat_is_not_supported_yet()
    {
        $this->expectException(ProvisioningException::class);

        app(ServerCreator::class)->create($this->order(['networkModel' => NetworkModel::Nat]));
    }

    public function test_suspend_and_unsuspend_stop_and_start_the_vm()
    {
        $server = app(ServerCreator::class)->create($this->order());

        app(TaskRunner::class)->start($server, TaskType::Suspend);
        $this->assertSame(ServerStatus::Suspended, $server->refresh()->status);
        $this->assertSame('stopped', $this->proxmox->vms[$server->vmid]['status']);

        app(TaskRunner::class)->start($server, TaskType::Unsuspend);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame('running', $this->proxmox->vms[$server->vmid]['status']);
        $this->assertSame(['start', 'wait_agent'], array_column($server->tasks()->latest('id')->first()->steps, 'key'));
    }

    public function test_unsuspending_waits_a_few_minutes_for_the_guest_agent()
    {
        $server = app(ServerCreator::class)->create($this->order());
        app(TaskRunner::class)->start($server, TaskType::Suspend);
        $this->proxmox->agentDown[$server->vmid] = true;
        Sleep::fake(syncWithCarbon: true);

        app(TaskRunner::class)->start($server, TaskType::Unsuspend);

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame('The guest agent did not answer within 3 minutes.', collect($server->tasks()->latest('id')->first()->steps)->firstWhere('key', 'wait_agent')['message']);
    }

    public function test_terminating_deletes_the_vm_and_frees_the_addresses()
    {
        $server = app(ServerCreator::class)->create($this->order());

        app(TaskRunner::class)->start($server, TaskType::Terminate);

        $server->refresh();
        $this->assertSame(ServerStatus::Terminated, $server->status);
        $this->assertNull($server->root_password);
        $this->assertArrayNotHasKey($server->vmid, $this->proxmox->vms);
        $this->assertSame(0, $server->ipAddresses()->count());
        $this->assertSame(0, IpAddress::query()->whereNotNull('server_id')->count());
    }

    public function test_terminating_waits_for_running_proxmox_tasks_that_hold_the_vm_lock()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $shutdown = "UPID:pve1:00005678:00ABCDEF:6512ABCD:qmshutdown:{$server->vmid}:root@pam:";
        $this->proxmox->activeTasks[$server->vmid] = [$shutdown];
        $this->proxmox->polledTasks = [];

        app(TaskRunner::class)->start($server, TaskType::Terminate);

        $this->assertSame(ServerStatus::Terminated, $server->refresh()->status);
        $this->assertSame($shutdown, $this->proxmox->polledTasks[0]);
        $this->assertStringContainsString(':qmstop:', $this->proxmox->polledTasks[1]);
    }

    public function test_only_one_task_runs_at_a_time()
    {
        $server = Server::factory()->for($this->node)->create();
        $server->tasks()->forceCreate(['type' => TaskType::Suspend, 'status' => TaskStatus::Running, 'steps' => []]);

        $this->expectException(ProvisioningException::class);

        app(TaskRunner::class)->start($server, TaskType::Terminate);
    }
}
