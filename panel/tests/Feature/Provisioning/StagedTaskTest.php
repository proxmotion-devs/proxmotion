<?php

namespace Tests\Feature\Provisioning;

use App\Domain\Servers\ServerReinstaller;
use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunServerTask;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\Stages\StagedPipeline;
use App\Provisioning\Stages\StageRunner;
use App\Provisioning\TaskFailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Creating and reinstalling run in stages (docs/adr/0032): a stage that has to
 * wait ends the job, which queues the next run with a delay, so the worker is
 * free in the meantime. Here jobs are run one by one as a worker would.
 */
class StagedTaskTest extends TestCase
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

    /**
     * The queued run of a task with the given dispatch number.
     */
    private function pushed(int $dispatch): RunServerTask
    {
        return Queue::pushed(RunServerTask::class, fn (RunServerTask $job) => $job->dispatch === $dispatch)->sole();
    }

    /**
     * Run a queued job like a worker: the task is read again, and the job is
     * not on the sync queue, so it queues its next run instead of sleeping.
     */
    private function work(RunServerTask $job): void
    {
        $job = unserialize(serialize($job));
        $job->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
    }

    private function queuedCreate(): Server
    {
        Queue::fake([RunServerTask::class]);

        return app(ServerCreator::class)->create($this->order());
    }

    private function step(Task $task, string $key): ?array
    {
        return collect($task->steps)->firstWhere('key', $key);
    }

    public function test_a_clone_that_outlasts_a_run_is_checked_again_in_later_runs()
    {
        $this->proxmox->runningPolls['qmclone'] = 2;
        $server = $this->queuedCreate();

        $this->work($this->pushed(0));

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Running, $task->status);
        $this->assertSame('clone', $task->stage);
        $this->assertSame(1, $task->dispatch);
        $this->assertStringStartsWith('UPID:', (string) $task->stageValue('upid'));
        $this->assertNotNull($task->heartbeat_at);
        $this->assertTrue($task->deadline_at->equalTo($task->started_at->copy()->addMinutes(120)));
        $this->assertSame(ServerStatus::Provisioning, $server->refresh()->status);
        Queue::assertPushed(RunServerTask::class, fn (RunServerTask $job) => $job->dispatch === 1 && $job->delay === 2);

        $this->work($this->pushed(1));
        $this->assertSame('clone', $task->refresh()->stage);
        $this->assertSame(2, $task->dispatch);

        $this->work($this->pushed(2));

        $task->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertNull($task->stage);
        $this->assertNull($task->stage_state);
        $this->assertSame('done', $this->step($task, 'clone')['status']);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
        $clones = array_filter($this->proxmox->polledTasks, fn (string $upid) => str_contains($upid, ':qmclone:'));
        $this->assertCount(3, $clones, 'One clone, checked once per run.');
        Queue::assertPushed(RunServerTask::class, 3);
    }

    public function test_a_reinstall_waits_for_the_deletion_in_a_later_run_and_keeps_the_mac_address()
    {
        $server = app(ServerCreator::class)->create($this->order());
        $vm = &$this->proxmox->vms[$server->vmid];
        $vm['config']['net0'] = (string) preg_replace('/=[0-9A-F:]{17}/i', '=BC:24:11:AA:BB:CC', (string) $vm['config']['net0']);
        unset($vm);

        Queue::fake([RunServerTask::class]);
        $this->proxmox->runningPolls['qmdestroy'] = 1;
        $task = app(ServerReinstaller::class)->request($server->refresh(), $server->osTemplate, [], User::factory()->create(), null);

        $this->work($this->pushed(0));

        $task->refresh();
        $this->assertSame('destroy', $task->stage);
        $this->assertStringContainsString('BC:24:11:AA:BB:CC', (string) $task->input['previous_net0']);
        $this->assertSame(ServerStatus::Reinstalling, $server->refresh()->status);

        $this->work($this->pushed(1));

        $task->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertSame(['stop', 'destroy', 'clone'], array_slice(array_column($task->steps, 'key'), 0, 3));
        $this->assertStringContainsString('BC:24:11:AA:BB:CC', (string) $this->proxmox->vms[$server->vmid]['config']['net0']);
        $this->assertSame(ServerStatus::Active, $server->refresh()->status);
    }

    public function test_replaced_runs_do_nothing_and_their_failure_leaves_the_task_alone()
    {
        $this->proxmox->runningPolls['qmclone'] = 5;
        $server = $this->queuedCreate();
        $this->work($this->pushed(0));
        $polls = count($this->proxmox->polledTasks);

        // A copy of the first run (the queue handed it out twice): the task is no longer queued.
        $this->work($this->pushed(0));
        $this->pushed(0)->failed(new RuntimeException('lost'));

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Running, $task->status);
        $this->assertSame(1, $task->dispatch);
        $this->assertCount($polls, $this->proxmox->polledTasks);
        Queue::assertPushed(RunServerTask::class, 2);

        // The latest run failing outside handle() ends the task.
        $this->pushed(1)->failed(new RuntimeException('lost'));

        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
        $this->assertSame(ServerStatus::Error, $server->refresh()->status);
    }

    public function test_a_task_an_administrator_ended_is_not_continued()
    {
        $this->proxmox->runningPolls['qmclone'] = 1;
        $server = $this->queuedCreate();
        $this->work($this->pushed(0));
        $task = $server->tasks()->sole();
        $polls = count($this->proxmox->polledTasks);

        app(TaskFailer::class)->fail($task, 'Marked as failed by an administrator.');
        $this->work($this->pushed(1));

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Marked as failed by an administrator.', $task->error);
        $this->assertCount($polls, $this->proxmox->polledTasks);
        $this->assertSame(ServerStatus::Error, $server->refresh()->status);
        Queue::assertPushed(RunServerTask::class, 2);
    }

    public function test_a_task_past_its_deadline_fails()
    {
        config(['proxmotion.provisioning.deadline_minutes' => 1]);
        $this->proxmox->agentDown[self::VMID] = true;
        Sleep::fake(syncWithCarbon: true);

        $server = app(ServerCreator::class)->create($this->order());

        $task = $server->tasks()->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('The task did not finish within 1 minutes.', $task->error);
        $this->assertSame('wait_agent', $task->stage);
        $this->assertSame(ServerStatus::Error, $server->refresh()->status);
    }

    public function test_a_run_stops_after_its_time_budget_and_queues_the_rest_at_once()
    {
        $server = $this->queuedCreate();
        $task = $server->tasks()->sole();
        $pipeline = new class implements StagedPipeline
        {
            /** @var list<string> */
            public array $ran = [];

            public function stages(): array
            {
                return ['first', 'second', 'third'];
            }

            public function runStage(Task $task, string $stage): ?int
            {
                $this->ran[] = $stage;
                Carbon::setTestNow(Carbon::now()->addSeconds(StageRunner::RUN_BUDGET_SECONDS));

                return null;
            }
        };

        $this->assertSame(0, app(StageRunner::class)->advance($task, $pipeline));
        $this->assertSame(['first'], $pipeline->ran);
        $this->assertSame('second', $task->stage);

        $this->assertSame(0, app(StageRunner::class)->advance($task, $pipeline));
        $this->assertNull(app(StageRunner::class)->advance($task, $pipeline));
        $this->assertSame(['first', 'second', 'third'], $pipeline->ran);
        $this->assertNull($task->stage);
    }

    public function test_a_running_task_can_be_ended_only_without_progress_or_past_its_deadline()
    {
        $server = $this->queuedCreate();
        $task = $server->tasks()->sole();
        $failer = app(TaskFailer::class);
        $task->forceFill([
            'status' => TaskStatus::Running,
            'started_at' => now()->subHours(2),
            'heartbeat_at' => now()->subMinute(),
            'deadline_at' => now()->addMinutes(10),
        ])->save();

        $this->assertFalse($failer->canAbandon($task), 'A long task that still makes progress.');

        $task->forceFill(['heartbeat_at' => now()->subSeconds(RunServerTask::TIMEOUT + 60)])->save();
        $this->assertTrue($failer->canAbandon($task), 'No progress for longer than a job may run.');

        $task->forceFill(['heartbeat_at' => now()->subMinute(), 'deadline_at' => now()->subMinute()])->save();
        $this->assertTrue($failer->canAbandon($task), 'Past its deadline.');
    }
}
