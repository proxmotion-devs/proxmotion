<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\HostnameChanger;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\Exceptions\TaskAlreadyRunning;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Renaming a server (docs/adr/0012): panel, PTRs, guest script; the Proxmox
 * VM keeps its name.
 */
class HostnameTest extends TestCase
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
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    private function rename(string $hostname = 'web2.example.com', bool $reboot = false): Task
    {
        return app(HostnameChanger::class)->request($this->server, $hostname, $reboot, $this->admin, '127.0.0.1')->refresh();
    }

    private function step(Task $task, string $key): ?array
    {
        return collect($task->steps)->firstWhere('key', $key);
    }

    public function test_a_linux_server_is_renamed_inside_without_touching_the_vm_name()
    {
        $vmName = $this->proxmox->vms[$this->server->vmid]['config']['name'];

        $task = $this->rename(' Web2.Example.com. ');

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(TaskType::ChangeHostname, $task->type);
        $this->assertSame('web2.example.com', $this->server->refresh()->hostname);
        $this->assertSame($vmName, $this->proxmox->vms[$this->server->vmid]['config']['name'], 'Renaming the VM would make cloud-init see a new instance (ADR 0012).');

        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $this->assertSame(file_get_contents(resource_path('guest/linux/hostname.sh')), $files['/usr/local/sbin/proxmotion-hostname']);
        $this->assertStringContainsString("hostname=web2.example.com\n", $files['/etc/proxmotion/hostname.vars']);
        $this->assertStringContainsString("previous_hostname=web1.example.com\n", $files['/etc/proxmotion/hostname.vars']);
        $this->assertNull($this->step($task, 'guest_hostname')['message']);
        $this->assertFalse($this->server->reboot_required, 'Linux needs no restart.');

        $audit = AuditLog::query()->where('action', 'server.hostname_changed')->sole();
        $this->assertSame(['from' => 'web1.example.com', 'to' => 'web2.example.com'], $audit->properties);
    }

    public function test_ptrs_equal_to_the_old_name_follow_it()
    {
        $same = $this->server->ipAddresses()->first();
        $same->forceFill(['ptr' => 'web1.example.com', 'ptr_synced_at' => now()])->save();

        $this->rename();

        $this->assertSame('web2.example.com', $same->refresh()->ptr);
        $this->assertSame('web2.example.com', AuditLog::query()->where('action', 'rdns.updated')->sole()->properties['to']);
    }

    public function test_chosen_ptrs_stay()
    {
        $address = $this->server->ipAddresses()->first();
        $address->forceFill(['ptr' => 'mail.example.com', 'ptr_synced_at' => now()])->save();

        $this->rename();

        $this->assertSame('mail.example.com', $address->refresh()->ptr);
    }

    public function test_windows_applies_the_name_at_the_next_restart()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();

        $task = $this->rename();

        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $this->assertSame(file_get_contents(resource_path('guest/windows/hostname.ps1')), $files['C:\\ProgramData\\Proxmotion\\hostname.ps1']);
        $this->assertTrue($this->server->refresh()->reboot_required);
        $this->assertSame('The new name takes effect when the server is restarted.', $this->step($task, 'apply')['message']);
    }

    public function test_windows_is_restarted_when_asked()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();

        $task = $this->rename(reboot: true);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNotNull($this->step($task, 'reboot'));
        $this->assertFalse($this->server->refresh()->reboot_required);
    }

    public function test_without_an_agent_the_name_still_changes_in_the_panel()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $task = $this->rename();

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame('web2.example.com', $this->server->refresh()->hostname);
        $this->assertSame('The guest agent did not answer: change the hostname inside the server.', $this->step($task, 'guest_hostname')['message']);
    }

    public function test_refusals_leave_the_name_alone()
    {
        try {
            $this->rename('web1.example.com');
            $this->fail('The same name was accepted.');
        } catch (ProvisioningException $e) {
            $this->assertStringContainsString('already called', $e->getMessage());
        }

        $running = new Task;
        $running->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();

        try {
            $this->rename();
            $this->fail('A second task was started.');
        } catch (TaskAlreadyRunning) {
        }

        $this->assertSame('web1.example.com', $this->server->refresh()->hostname, 'Rolled back.');
    }

    public function test_admins_rename_from_the_server_page_and_the_api_does_too()
    {
        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.hostname', $this->server), ['hostname' => 'web2.example.com'])
            ->assertRedirect(route('admin.servers.show', $this->server))
            ->assertSessionHasNoErrors();
        $this->assertSame('web2.example.com', $this->server->refresh()->hostname);

        $this->actingAs($this->admin)
            ->post(route('admin.servers.hostname', $this->server), ['hostname' => 'not a hostname'])
            ->assertSessionHasErrors('hostname');
        $this->actingAs($this->admin)
            ->post(route('admin.servers.hostname', $this->server), ['hostname' => 'web2.example.com'])
            ->assertSessionHasErrors('hostname');
    }
}
