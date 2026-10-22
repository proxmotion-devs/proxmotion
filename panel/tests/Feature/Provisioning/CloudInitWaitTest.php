<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Servers\ServerReinstaller;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * A new or reinstalled Linux server is ready once cloud-init has finished,
 * not when its guest agent first answers: cloud-init would otherwise undo
 * what the guest scripts set right after the agent answered (the hostname,
 * /etc/hosts). Problems leave a note and the operation goes on.
 */
class CloudInitWaitTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private const VMID = 100;

    private FakeProxmoxCluster $proxmox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    private function waitStep(Task $task): array
    {
        return collect($task->steps)->firstWhere('key', 'wait_agent');
    }

    public function test_a_new_server_is_active_only_once_cloud_init_has_finished()
    {
        $this->proxmox->cloudInitStatus[self::VMID] = ['status: not started', 'status: running', "status: done\nextended_status: done"];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(3, $this->proxmox->cloudInitChecks[self::VMID]);
        Sleep::assertSleptTimes(2);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertNull($this->waitStep($server->tasks()->sole())['message']);
    }

    public function test_a_system_without_cloud_init_is_ready_at_once()
    {
        $this->proxmox->cloudInitStatus[self::VMID] = [''];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(1, $this->proxmox->cloudInitChecks[self::VMID]);
        Sleep::assertNeverSlept();
        $this->assertNull($this->waitStep($server->tasks()->sole())['message']);
    }

    public function test_a_failed_cloud_init_leaves_a_note_and_the_server_goes_on()
    {
        $this->proxmox->cloudInitStatus[self::VMID] = ['status: error'];

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame(
            'cloud-init finished with status error: check /var/log/cloud-init.log inside the server.',
            $this->waitStep($server->tasks()->sole())['message'],
        );
    }

    public function test_a_cloud_init_that_does_not_finish_in_time_leaves_a_note()
    {
        $this->proxmox->cloudInitStatus[self::VMID] = ['status: running'];
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('cloud-init was still running after 10 minutes: the server may still be setting itself up.', $this->waitStep($task)['message']);
    }

    public function test_an_agent_that_refuses_commands_is_not_waited_for()
    {
        // RHEL-family templates block guest-exec unless the template guide was followed.
        $this->proxmox->agentBlocked[self::VMID] = ['exec'];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        Sleep::assertNeverSlept();
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertStringStartsWith(
            'Could not check whether cloud-init has finished: The guest agent of VM 100 refuses guest-exec',
            $this->waitStep($server->tasks()->sole())['message'],
        );
    }

    public function test_a_reinstall_waits_for_cloud_init_too_but_an_unsuspend_does_not()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->proxmox->cloudInitChecks = [];
        $this->proxmox->cloudInitStatus[self::VMID] = ['status: running', 'status: done'];
        Sleep::fake();

        $task = app(ServerReinstaller::class)->request($server, $server->osTemplate, [], User::factory()->create(), null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(2, $this->proxmox->cloudInitChecks[self::VMID]);

        $this->proxmox->cloudInitChecks = [];
        app(TaskRunner::class)->start($server->refresh(), TaskType::Suspend);
        app(TaskRunner::class)->start($server->refresh(), TaskType::Unsuspend);

        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $this->assertSame([], $this->proxmox->cloudInitChecks);
    }
}
