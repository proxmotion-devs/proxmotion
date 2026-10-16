<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\ProxmoxTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;
use Tests\Unit\PublicKeyTest;

class ReinstallTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private OsTemplate $debian;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake(syncWithCarbon: true);
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->addTemplate(9001)->install();
        $this->server = app(ServerCreator::class)->create($this->order());
        $this->server->forceFill(['root_password_unrevealed' => false])->save();

        $this->debian = OsTemplate::factory()->create(['name' => 'Debian 12']);
        $this->debian->proxmoxTemplates()->attach(ProxmoxTemplate::query()->forceCreate([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->id,
            'vmid' => 9001,
            'name' => 'tpl-debian-12',
        ]));

        // The running VM has its own MAC address, unlike the template.
        $vm = &$this->proxmox->vms[$this->server->vmid];
        $vm['config']['net0'] = (string) preg_replace('/=[0-9A-F:]{17}/i', '=BC:24:11:AA:BB:CC', (string) $vm['config']['net0']);
        $vm['config']['scsi0'] .= ',marker=old-disk';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reinstall(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.reinstall', $this->server), [
                'os_template' => $this->debian->uuid,
                'ssh_keys' => '',
                'confirm_hostname' => $this->server->hostname,
                ...$overrides,
            ]);
    }

    public function test_admins_can_reinstall_with_another_os_and_new_keys()
    {
        $vmid = $this->server->vmid;
        $addresses = $this->server->ipAddresses()->pluck('address')->all();
        $oldPassword = $this->server->root_password;

        // The same key twice: it is installed once.
        $this->reinstall(['ssh_keys' => PublicKeyTest::ED25519."\n\n".PublicKeyTest::ED25519, 'confirm_hostname' => strtoupper($this->server->hostname)])
            ->assertRedirect(route('admin.servers.show', $this->server))
            ->assertSessionHasNoErrors();

        $server = $this->server->refresh();
        $task = Task::query()->where('type', TaskType::Reinstall)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(['stop', 'destroy', 'clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent'], array_column($task->steps, 'key'));

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame($vmid, $server->vmid);
        $this->assertSame($addresses, $server->ipAddresses()->pluck('address')->all());
        $this->assertSame($this->debian->id, $server->os_template_id);
        $this->assertNotSame($oldPassword, $server->root_password);
        $this->assertTrue($server->root_password_unrevealed);
        $this->assertSame(PublicKeyTest::ED25519, $server->ssh_keys);

        $config = $this->proxmox->vms[$vmid]['config'];
        $this->assertStringNotContainsString('old-disk', (string) $config['scsi0'], 'The old disks are gone.');
        $this->assertStringContainsString('BC:24:11:AA:BB:CC', (string) $config['net0'], 'The MAC address is kept.');
        $this->assertSame($server->root_password, $config['cipassword']);
        $this->assertSame(rawurlencode(PublicKeyTest::ED25519), $config['sshkeys']);
        $this->assertContains('POST /nodes/pve1/qemu/9001/clone', $this->proxmox->writePaths());
    }

    public function test_reinstalls_are_audited_without_secrets()
    {
        $this->reinstall(['ssh_keys' => PublicKeyTest::ED25519]);

        $log = AuditLog::query()->where('action', 'server.reinstall')->sole();
        $this->assertSame('Debian 12', $log->properties['os_template'] ?? null);
        $this->assertSame(['SHA256:nvWikPWjXWJFiw36iQyJQvpJbc5NtwfchOzuV9sx2CI'], $log->properties['ssh_keys'] ?? null);
        $this->assertStringNotContainsString((string) $this->server->refresh()->root_password, (string) json_encode($log->properties));
    }

    public function test_the_server_is_active_even_if_the_new_system_has_no_guest_agent()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $this->reinstall();

        $task = Task::query()->where('type', TaskType::Reinstall)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertStringContainsString('did not answer', (string) collect($task->steps)->firstWhere('key', 'wait_agent')['message']);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
    }

    public function test_the_hostname_must_be_typed_to_confirm()
    {
        $this->reinstall(['confirm_hostname' => 'something-else'])->assertSessionHasErrors('confirm_hostname');

        $this->assertFalse(Task::query()->where('type', TaskType::Reinstall)->exists());
        $this->assertSame($this->osTemplate->id, $this->server->refresh()->os_template_id);
    }

    public function test_only_os_templates_available_on_the_cluster_can_be_chosen()
    {
        $elsewhere = OsTemplate::factory()->create(['name' => 'Not here']);

        $this->reinstall(['os_template' => $elsewhere->uuid])->assertSessionHasErrors('os_template');
    }

    public function test_suspended_servers_cannot_be_reinstalled()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->reinstall()->assertForbidden();
    }

    public function test_nothing_changes_while_another_task_runs()
    {
        $running = new Task;
        $running->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();
        $password = $this->server->root_password;

        $this->reinstall();

        $server = $this->server->refresh();
        $this->assertSame($password, $server->root_password);
        $this->assertSame($this->osTemplate->id, $server->os_template_id);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'server.reinstall')->count());
    }

    public function test_a_failed_reinstall_leaves_the_server_in_error_and_can_be_retried()
    {
        $this->proxmox->failClone = 'clone failed: storage full';

        $this->reinstall();

        $this->assertSame(ServerStatus::Error, $this->server->refresh()->status);

        $this->proxmox->failClone = null;
        $this->actingAs($this->admin)->post(route('admin.servers.retry', $this->server));

        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status);
        $this->assertSame(TaskType::Reinstall, Task::query()->latest('id')->firstOrFail()->type);
    }

    public function test_invalid_ssh_keys_are_rejected()
    {
        $this->reinstall(['ssh_keys' => 'not a key'])->assertSessionHasErrors('ssh_keys');

        $this->assertFalse(Task::query()->where('type', TaskType::Reinstall)->exists());
    }

    public function test_the_page_offers_the_templates_available_on_the_cluster()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->has('reinstallOptions.osTemplates', 2));
    }
}
