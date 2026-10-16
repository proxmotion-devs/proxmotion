<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use Tests\Unit\PublicKeyTest;

/**
 * What the billing module does with a server: suspend, unsuspend,
 * terminate, power, reinstall, root password and rDNS.
 */
class ServerOperationsTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}{$suffix}";
    }

    private function vm(): array
    {
        return $this->proxmox->vms[$this->server->vmid];
    }

    private function auditedWithToken(string $action): AuditLog
    {
        $log = AuditLog::query()->where('action', $action)->sole();
        $this->assertSame($this->token->id, $log->api_token_id);
        $this->assertNull($log->user_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertTrue($log->subject?->is($this->server));

        return $log;
    }

    public function test_a_server_is_suspended_with_a_reason_and_unsuspended()
    {
        $this->api('POST', $this->url('/suspend'), ['reason' => 'Overdue invoice #1234'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'suspend')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.server.status', 'suspended');

        $this->server->refresh();
        $this->assertSame(ServerStatus::Suspended, $this->server->status);
        $this->assertSame('Overdue invoice #1234', $this->server->suspension_reason);
        $this->assertSame('stopped', $this->vm()['status']);
        $this->auditedWithToken('server.suspended');

        $this->api('POST', $this->url('/unsuspend'))->assertAccepted()->assertJsonPath('data.server.status', 'active');
        $this->server->refresh();
        $this->assertSame(ServerStatus::Active, $this->server->status);
        $this->assertNull($this->server->suspension_reason);
        $this->assertSame('running', $this->vm()['status']);
        $this->auditedWithToken('server.unsuspended');
    }

    public function test_actions_that_do_not_fit_the_status_are_conflicts()
    {
        $this->api('POST', $this->url('/unsuspend'))
            ->assertConflict()
            ->assertExactJson(['message' => 'This action is not possible while the server is active.']);

        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        foreach (['/suspend', '/power', '/reinstall', '/password', '/console'] as $suffix) {
            $this->api('POST', $this->url($suffix), ['action' => 'start', 'os_template' => $this->osTemplate->uuid])->assertConflict();
        }

        $this->assertSame(1, $this->server->tasks()->count(), 'Only the create task.');
    }

    public function test_a_second_operation_while_a_task_runs_is_a_conflict()
    {
        $running = new Task;
        $running->forceFill(['server_id' => $this->server->id, 'type' => TaskType::Reboot, 'status' => TaskStatus::Running, 'steps' => []])->save();

        $this->api('POST', $this->url('/power'), ['action' => 'stop'])
            ->assertConflict()
            ->assertExactJson(['message' => 'Another task is still running for this server.']);
        $this->api('POST', $this->url('/suspend'))->assertConflict();
        $this->assertSame('running', $this->vm()['status']);
    }

    public function test_a_server_is_terminated()
    {
        $this->api('DELETE', $this->url())
            ->assertAccepted()
            ->assertJsonPath('data.type', 'terminate')
            ->assertJsonPath('data.server.status', 'terminated');

        $this->assertSame(ServerStatus::Terminated, $this->server->refresh()->status);
        $this->assertArrayNotHasKey($this->server->vmid, $this->proxmox->vms);
        $this->assertSame(0, $this->server->ipAddresses()->count());
        $this->auditedWithToken('server.terminated');

        $this->api('DELETE', $this->url())->assertConflict();
    }

    public function test_power_actions()
    {
        $this->api('POST', $this->url('/power'), ['action' => 'stop'])->assertAccepted()->assertJsonPath('data.type', 'stop');
        $this->assertSame('stopped', $this->vm()['status']);

        $this->api('POST', $this->url('/power'), ['action' => 'start'])->assertAccepted();
        $this->assertSame('running', $this->vm()['status']);

        $this->api('POST', $this->url('/power'), ['action' => 'hibernate'])->assertUnprocessable()->assertJsonValidationErrors(['action']);

        $this->assertSame(['stop', 'start'], AuditLog::query()->where('action', 'server.power')->orderBy('id')->get()->map(fn (AuditLog $log) => $log->properties['action'] ?? null)->all());
        $this->assertSame($this->token->id, Task::query()->where('type', TaskType::Stop)->sole()->api_token_id);
    }

    public function test_a_server_is_reinstalled()
    {
        $this->api('POST', $this->url('/reinstall'), [
            'os_template' => $this->osTemplate->uuid,
            'ssh_keys' => [PublicKeyTest::ED25519],
        ])->assertAccepted()->assertJsonPath('data.type', 'reinstall')->assertJsonPath('data.status', 'succeeded');

        $this->server->refresh();
        $this->assertSame(ServerStatus::Active, $this->server->status);
        $this->assertTrue($this->server->root_password_unrevealed, 'The new password can be fetched once.');
        $this->auditedWithToken('server.reinstall');
    }

    public function test_reinstalling_needs_an_os_template_of_the_servers_cluster()
    {
        $this->api('POST', $this->url('/reinstall'), ['os_template' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['os_template']);

        $this->api('POST', $this->url('/reinstall'), ['os_template' => $this->osTemplate->uuid, 'ssh_keys' => ['not a key']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ssh_keys.0']);
    }

    public function test_the_root_password_is_handed_out_once()
    {
        $this->api('GET', $this->url('/password'))
            ->assertOk()
            ->assertExactJson(['data' => ['root_password' => 'a-very-long-password']]);

        $this->api('GET', $this->url('/password'))
            ->assertGone()
            ->assertExactJson(['message' => 'The root password has already been fetched. Reset it to get a new one.']);

        $this->assertFalse($this->server->refresh()->root_password_unrevealed);
        $log = $this->auditedWithToken('server.password_fetched');
        $this->assertStringNotContainsString('a-very-long-password', (string) json_encode($log->properties));
    }

    public function test_a_reset_password_can_be_fetched_once_more()
    {
        $this->api('GET', $this->url('/password'))->assertOk();

        $this->api('POST', $this->url('/password'))->assertAccepted()->assertJsonPath('data.type', 'reset_password');
        $password = $this->server->refresh()->root_password;
        $this->assertNotSame('a-very-long-password', $password);
        $this->assertSame($password, $this->proxmox->guestPasswords[$this->server->vmid]['root']);

        $this->api('GET', $this->url('/password'))->assertOk()->assertJsonPath('data.root_password', $password);
        $this->api('GET', $this->url('/password'))->assertGone();
    }

    public function test_reverse_dns_of_the_servers_own_address_can_be_set_and_removed()
    {
        $address = $this->server->ipAddresses()->firstOrFail();

        $this->api('POST', $this->url('/rdns'), ['address' => $address->address, 'hostname' => 'Mail.Example.com.'])
            ->assertOk()
            ->assertExactJson(['data' => ['address' => $address->address, 'ptr' => 'mail.example.com']]);
        $this->assertSame('mail.example.com', $address->refresh()->ptr);
        $this->auditedWithToken('rdns.updated');

        $this->api('POST', $this->url('/rdns'), ['address' => $address->address, 'hostname' => null])
            ->assertOk()
            ->assertJsonPath('data.ptr', null);
    }

    public function test_reverse_dns_of_another_servers_address_is_refused()
    {
        $other = $this->provisionedServer('whmcs-other');
        $foreign = $other->ipAddresses()->firstOrFail();

        $this->api('POST', $this->url('/rdns'), ['address' => $foreign->address, 'hostname' => 'evil.example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);

        $this->assertNull($foreign->refresh()->ptr);
    }
}
