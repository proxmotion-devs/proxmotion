<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\ServerSshKeys;
use App\Domain\Settings\PanelSettings;
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

    public function test_freebsd_servers_share_the_portable_linux_script()
    {
        $this->osTemplate->forceFill(['distribution' => 'freebsd', 'version' => '14.3'])->save();

        $this->change([PublicKeyTest::RSA]);

        $this->assertSame(file_get_contents(resource_path('guest/linux/ssh-keys.sh')), $this->proxmox->guestFiles[$this->server->vmid][self::SCRIPT]);
    }

    public function test_the_limit_comes_from_panel_settings_everywhere_keys_are_given()
    {
        PanelSettings::save(['ssh_keys_max' => 2]);
        $three = [PublicKeyTest::ED25519, PublicKeyTest::RSA, PublicKeyTest::ECDSA];

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('sshKeys.max', 2));

        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), ['ssh_keys' => $three])
            ->assertSessionHasErrors(['ssh_keys' => 'A server can have at most 2 SSH keys.']);
        $this->actingAs($this->admin)->post(route('admin.servers.reinstall', $this->server), [
            'os_template' => $this->osTemplate->uuid,
            'ssh_keys' => implode("\n", $three),
            'confirm_hostname' => $this->server->hostname,
        ])->assertSessionHasErrors('ssh_keys');

        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
        $this->assertSame(0, $this->server->tasks()->whereIn('type', [TaskType::ChangeSshKeys, TaskType::Reinstall])->count());
    }

    public function test_the_keys_together_stay_small_enough_for_the_guest_agent()
    {
        PanelSettings::save(['ssh_keys_max' => 100]);

        // 70 keys with long comments: about 23 KB.
        $keys = self::distinctKeys(70, 250);

        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), ['ssh_keys' => $keys])
            ->assertSessionHasErrors(['ssh_keys' => 'The SSH keys are too long together: at most 20 KB.']);

        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), ['ssh_keys' => array_slice($keys, 0, 50)])
            ->assertSessionHasNoErrors();
        $this->assertCount(50, ServerSshKeys::of($this->server->refresh()));
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

    public function test_a_stored_key_today_s_rules_refuse_is_left_out_but_still_removed()
    {
        $old = 'ssh-dss AAAAB3NzaC1kc3M= old@example.com';
        $this->server->forceFill(['ssh_keys' => PublicKeyTest::ED25519."\n".$old])->save();

        $this->actingAs($this->admin)->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sshKeys.keys', 1));

        $task = $this->change([PublicKeyTest::ED25519]);

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame([$old], $task->input['removed_ssh_keys']);
        $this->assertStringContainsString("removed_ssh_keys={$old}\n", $this->vars());
        $this->assertSame(PublicKeyTest::ED25519, $this->server->refresh()->ssh_keys);
    }

    public function test_the_keys_inside_the_server_are_read_next_to_the_managed_ones()
    {
        $this->server->forceFill(['ssh_keys' => PublicKeyTest::ED25519."\n".PublicKeyTest::ECDSA])->save();
        $this->proxmox->guestFiles[$this->server->vmid]['/root/.ssh/authorized_keys'] = implode("\n", [
            '# keys of the team',
            PublicKeyTest::ED25519,
            'command="/usr/bin/backup",no-pty '.PublicKeyTest::RSA,
            'ssh-dss AAAAB3NzaC1kc3M= old@example.com',
            '',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('admin.servers.ssh-keys.guest', $this->server))
            ->assertOk();

        $this->assertSame([
            ['type' => 'ssh-ed25519', 'fingerprint' => $this->fingerprint(PublicKeyTest::ED25519), 'comment' => 'test@example.com', 'public_key' => PublicKeyTest::ED25519, 'file' => '/root/.ssh/authorized_keys', 'options' => false, 'managed' => true],
            ['type' => 'ssh-rsa', 'fingerprint' => $this->fingerprint(PublicKeyTest::RSA), 'comment' => 'rsa@example.com', 'public_key' => PublicKeyTest::RSA, 'file' => '/root/.ssh/authorized_keys', 'options' => true, 'managed' => false],
        ], $response->json('keys'));
        $this->assertSame([$this->fingerprint(PublicKeyTest::ECDSA)], $response->json('missing'));
        $this->assertSame(1, $response->json('unsupported'));
        $this->assertSame(0, $this->server->tasks()->where('type', TaskType::ChangeSshKeys)->count(), 'Reading changes nothing.');
    }

    public function test_windows_servers_are_read_in_both_files_and_without_an_agent_nothing_is()
    {
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();
        $this->proxmox->guestFiles[$this->server->vmid]['C:\\Users\\Administrator\\.ssh\\authorized_keys'] = PublicKeyTest::RSA."\r\n";

        $this->actingAs($this->admin)
            ->getJson(route('admin.servers.ssh-keys.guest', $this->server))
            ->assertOk()
            ->assertJsonPath('keys.0.file', 'C:\\Users\\Administrator\\.ssh\\authorized_keys')
            ->assertJsonPath('missing', [$this->fingerprint(PublicKeyTest::ED25519)]);

        $this->proxmox->agentDown[$this->server->vmid] = true;

        $this->actingAs($this->admin)
            ->getJson(route('admin.servers.ssh-keys.guest', $this->server))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Could not read the SSH keys inside web1.example.com: its guest agent does not answer.');
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

    public function test_a_server_has_at_most_twenty_keys_by_default()
    {
        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => self::distinctKeys(21),
        ])->assertSessionHasErrors(['ssh_keys' => 'A server can have at most 20 SSH keys.']);

        // Duplicates collapse before the count.
        $this->actingAs($this->admin)->put(route('admin.servers.ssh-keys', $this->server), [
            'ssh_keys' => array_fill(0, 21, PublicKeyTest::RSA),
        ])->assertSessionHasNoErrors();

        $this->assertSame(PublicKeyTest::RSA, $this->server->refresh()->ssh_keys);
    }

    /**
     * Distinct ed25519 keys (random, never used to log in), with a comment
     * padded to $comment characters.
     *
     * @return list<string>
     */
    public static function distinctKeys(int $count, int $comment = 20): array
    {
        return array_map(
            fn (int $i) => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).random_bytes(32)).' '.str_pad("key{$i}@", $comment, 'x'),
            range(1, $count),
        );
    }
}
