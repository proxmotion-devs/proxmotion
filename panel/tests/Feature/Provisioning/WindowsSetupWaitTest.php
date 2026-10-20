<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Servers\GuestScripts;
use App\Domain\Servers\ServerReinstaller;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
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
 * A new or reinstalled Windows server is ready once Windows setup has
 * finished, not when its guest agent first answers (during sysprep's
 * specialize pass). Linux servers and unsuspends only wait for the agent.
 */
class WindowsSetupWaitTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    /** @var list<string> what the setup check prints, one answer per check; the last one repeats */
    private array $answers = [];

    private int $checks = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->osTemplate->forceFill(['platform' => 'windows'])->save();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->proxmox->execHandler = function (array $command) {
            if ($command !== GuestScripts::WINDOWS_SETUP_COMMAND) {
                return [0, '', ''];
            }

            $answer = $this->answers[min($this->checks, count($this->answers) - 1)] ?? "IMAGE_STATE_COMPLETE\r\nStopped\r\n";
            $this->checks++;

            return [0, $answer, ''];
        };
    }

    private function waitStep(Task $task): array
    {
        return collect($task->steps)->firstWhere('key', 'wait_agent');
    }

    public function test_a_windows_server_is_active_only_once_setup_has_finished()
    {
        $this->answers = [
            "IMAGE_STATE_SPECIALIZE_RESEAL_TO_OOBE\r\n\r\n",
            "IMAGE_STATE_COMPLETE\r\nRunning\r\n",
            "IMAGE_STATE_COMPLETE\r\nStopped\r\n",
        ];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(3, $this->checks);
        Sleep::assertSleptTimes(2);
        $this->assertSame(ServerStatus::Active, $server->status);
        $step = $this->waitStep($server->tasks()->sole());
        $this->assertSame('done', $step['status']);
        $this->assertNull($step['message']);
    }

    public function test_a_finished_windows_setup_without_cloudbase_init_counts_as_finished()
    {
        $this->answers = ["IMAGE_STATE_COMPLETE\r\n\r\n"];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(1, $this->checks);
        Sleep::assertNeverSlept();
        $this->assertSame(ServerStatus::Active, $server->status);
    }

    public function test_the_wait_goes_on_while_windows_restarts_during_setup()
    {
        $vmid = null;
        $sleeps = 0;
        $this->proxmox->execHandler = function (array $command) use (&$vmid) {
            if ($command !== GuestScripts::WINDOWS_SETUP_COMMAND) {
                return [0, '', ''];
            }

            // The first check: Windows restarts into OOBE while it runs.
            if (++$this->checks === 1) {
                $vmid = array_key_last($this->proxmox->vms);
                $this->proxmox->agentDown[$vmid] = true;

                return [0, "IMAGE_STATE_SPECIALIZE_RESEAL_TO_OOBE\r\n\r\n", ''];
            }

            return [0, "IMAGE_STATE_COMPLETE\r\nStopped\r\n", ''];
        };
        Sleep::fake();
        Sleep::whenFakingSleep(function () use (&$vmid, &$sleeps) {
            if (++$sleeps === 3) {
                $this->proxmox->agentDown[$vmid] = false;
            }
        });

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(2, $this->checks, 'Checked again once the agent was back.');
        $this->assertSame(3, $sleeps);
        $this->assertSame(ServerStatus::Active, $server->status);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertNull($this->waitStep($task)['message']);
    }

    public function test_a_setup_that_does_not_finish_in_time_leaves_a_note_and_the_server_goes_on()
    {
        $this->answers = ["IMAGE_STATE_UNDEPLOYABLE\r\n\r\n"];
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(ServerStatus::Active, $server->status);
        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(
            'Windows setup did not finish within '.intdiv(GuestScripts::WINDOWS_SETUP_TIMEOUT_SECONDS, 60).' minutes: the server may still be setting itself up.',
            $this->waitStep($task)['message'],
        );
    }

    public function test_a_windows_agent_that_never_answers_still_times_out_after_ten_minutes()
    {
        $this->proxmox->agentDown[100] = true;
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(0, $this->checks);
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertSame('The guest agent did not answer within 10 minutes.', $this->waitStep($server->tasks()->sole())['message']);
    }

    public function test_a_reinstall_waits_for_windows_setup_too()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->checks = 0;
        $this->answers = ["IMAGE_STATE_SPECIALIZE_RESEAL_TO_OOBE\r\n\r\n", "IMAGE_STATE_COMPLETE\r\nStopped\r\n"];
        Sleep::fake();

        $task = app(ServerReinstaller::class)->request($server, $server->osTemplate, [], User::factory()->create(), null)->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status);
        $this->assertSame(2, $this->checks);
        $this->assertNull($this->waitStep($task)['message']);
    }

    public function test_unsuspending_a_windows_server_only_waits_for_the_agent()
    {
        $server = app(ServerCreator::class)->create($this->order())->refresh();
        app(TaskRunner::class)->start($server, TaskType::Suspend);
        $this->checks = 0;

        app(TaskRunner::class)->start($server, TaskType::Unsuspend);

        $this->assertSame(0, $this->checks);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
    }

    public function test_linux_servers_only_wait_for_the_agent()
    {
        $this->osTemplate->forceFill(['platform' => 'linux'])->save();
        $this->answers = ["IMAGE_STATE_UNDEPLOYABLE\r\n\r\n"];
        Sleep::fake();

        $server = app(ServerCreator::class)->create($this->order())->refresh();

        $this->assertSame(0, $this->checks);
        $this->assertSame([], $this->proxmox->guestCommands);
        Sleep::assertNeverSlept();
        $this->assertSame(ServerStatus::Active, $server->status);
        $this->assertNull($this->waitStep($server->tasks()->sole())['message']);
    }
}
