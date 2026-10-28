<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\ServerSshKeys;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\Exceptions\ProvisioningException;
use App\Provisioning\ServerCreator;
use App\Support\Ssh\PublicKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;
use Tests\Unit\PublicKeyTest;

/**
 * SSH keys on a running server (docs/adr/0049): written through the
 * ssh_keys_changed guest script, stored once it succeeded, never in
 * Proxmox's cloud-init settings.
 */
class SshKeysTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private const SCRIPT = '/usr/local/sbin/proxmotion-ssh-keys';

    private const VARS = '/etc/proxmotion/ssh-keys.vars';

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order(['sshKeys' => PublicKeyTest::ED25519]))->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @param  list<string>  $lines
     */
    private function change(array $lines): Task
    {
        $keys = PublicKey::parseMany(implode("\n", $lines));

        return app(ServerSshKeys::class)->change($this->server, $keys, $this->admin, '127.0.0.1')->refresh();
    }

    private function fingerprint(string $line): string
    {
        return PublicKey::parse($line)->fingerprint();
    }

    private function vars(): string
    {
        return $this->proxmox->guestFiles[$this->server->vmid][self::VARS];
    }

    public function test_a_key_is_added_inside_the_server_and_then_listed()
    {
        $sshkeys = $this->proxmox->vms[$this->server->vmid]['config']['sshkeys'] ?? null;

        $task = $this->change([PublicKeyTest::ED25519, PublicKeyTest::RSA]);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(TaskType::ChangeSshKeys, $task->type);
        $this->assertSame(file_get_contents(resource_path('guest/linux/ssh-keys.sh')), $this->proxmox->guestFiles[$this->server->vmid][self::SCRIPT]);
        $this->assertStringContainsString("username=root\n", $this->vars());
        $this->assertStringContainsString('ssh_keys='.PublicKeyTest::ED25519."\n", $this->vars());
        $this->assertStringContainsString('ssh_keys='.PublicKeyTest::RSA."\n", $this->vars());
        $this->assertStringNotContainsString('removed_ssh_keys=', $this->vars());
        $this->assertSame(PublicKeyTest::ED25519."\n".PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
        $this->assertSame($sshkeys, $this->proxmox->vms[$this->server->vmid]['config']['sshkeys'] ?? null, 'Changing cloud-init would give the server new SSH host keys (ADR 0049).');

        $audit = AuditLog::query()->where('action', 'server.ssh_keys_changed')->sole();
        $this->assertSame(['added' => [$this->fingerprint(PublicKeyTest::RSA)], 'removed' => []], $audit->properties);
    }

    public function test_a_deleted_key_is_removed_inside_the_server()
    {
        $this->change([PublicKeyTest::ED25519, PublicKeyTest::RSA]);

        $task = $this->change([PublicKeyTest::RSA]);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertStringContainsString('removed_ssh_keys='.PublicKeyTest::ED25519."\n", $this->vars());
        $this->assertStringNotContainsString('ssh_keys='.PublicKeyTest::ED25519."\nssh", $this->vars());
        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
        $this->assertSame(['ssh_keys' => [PublicKeyTest::RSA], 'removed_ssh_keys' => [PublicKeyTest::ED25519]], $task->input);

        $this->assertSame(['ssh_keys' => [], 'removed_ssh_keys' => [PublicKeyTest::RSA]], $this->change([])->input);
        $this->assertNull($this->server->refresh()->ssh_keys);
    }

    public function test_a_failing_script_fails_the_change_and_keeps_the_list_so_it_can_be_sent_again()
    {
        $this->proxmox->execHandler = fn (array $command) => $command === ['sh', self::SCRIPT] ? [1, '', 'Read-only file system'] : [0, '', ''];

        $task = $this->change([PublicKeyTest::RSA]);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertStringContainsString('The SSH keys could not be changed inside the server: ', (string) $task->error);
        $this->assertStringContainsString('Read-only file system', (string) $task->error);
        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);

        $this->proxmox->execHandler = null;
        $task = $this->change([PublicKeyTest::RSA]);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertStringContainsString('removed_ssh_keys='.PublicKeyTest::ED25519."\n", $this->vars(), 'The retry removes the key again.');
        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
    }

    public function test_without_an_agent_nothing_changes()
    {
        $this->proxmox->agentDown[$this->server->vmid] = true;

        $task = $this->change([]);

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
    }

    public function test_windows_servers_get_the_keys_of_administrator()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();

        $task = $this->change([PublicKeyTest::RSA]);

        $files = $this->proxmox->guestFiles[$this->server->vmid];
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(file_get_contents(resource_path('guest/windows/ssh-keys.ps1')), $files['C:\\ProgramData\\Proxmotion\\ssh-keys.ps1']);
        $inputs = json_decode($files['C:\\ProgramData\\Proxmotion\\ssh-keys.json'], true);
        $this->assertSame('Administrator', $inputs['username']);
        $this->assertSame([PublicKeyTest::RSA], $inputs['ssh_keys']);
        $this->assertSame([PublicKeyTest::ED25519], $inputs['removed_ssh_keys']);
    }

    public function test_freebsd_servers_run_their_own_script()
    {
        $this->osTemplate->forceFill(['distribution' => 'freebsd', 'version' => '14.3'])->save();

        $this->change([PublicKeyTest::RSA]);

        $this->assertSame(file_get_contents(resource_path('guest/freebsd/ssh-keys.sh')), $this->proxmox->guestFiles[$this->server->vmid][self::SCRIPT]);
    }

    public function test_a_trial_run_writes_the_server_s_keys_and_removes_none()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.os-templates.scripts.run', [$this->osTemplate, 'ssh_keys_changed']), ['server' => $this->server->uuid])
            ->assertSessionHasNoErrors();

        $task = $this->server->tasks()->where('type', TaskType::GuestScript)->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame('guest_ssh_keys', collect($task->steps)->last()['key']);
        $this->assertNotNull(collect($task->steps)->last()['finished_at'] ?? null, 'A critical script leaves its step to the caller.');
        $this->assertStringContainsString('ssh_keys='.PublicKeyTest::ED25519."\n", $this->vars());
        $this->assertStringNotContainsString('removed_ssh_keys=', $this->vars());
        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
    }

    public function test_only_active_servers_change_their_keys()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->expectException(ProvisioningException::class);

        $this->change([]);
    }

    public function test_admins_add_and_delete_keys_on_the_server_page()
    {
        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sshKeys.max', 20)
                ->where('sshKeys.keys.0.fingerprint', $this->fingerprint(PublicKeyTest::ED25519))
                ->where('sshKeys.keys.0.comment', 'test@example.com'));

        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => [PublicKeyTest::ED25519, PublicKeyTest::RSA."\n".PublicKeyTest::ECDSA],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertCount(3, ServerSshKeys::of($this->server->refresh()));

        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => [PublicKeyTest::ED25519, 'ssh-dss AAAAB3NzaC1kc3M= old@example.com'],
        ])->assertSessionHasErrors('ssh_keys.1');

        $this->assertCount(3, ServerSshKeys::of($this->server->refresh()));
    }

    public function test_a_server_has_at_most_twenty_keys()
    {
        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => array_fill(0, 21, PublicKeyTest::RSA),
        ])->assertSessionHasErrors('ssh_keys');

        // Duplicates collapse before the count.
        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => array_fill(0, 20, PublicKeyTest::RSA),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
    }
}
