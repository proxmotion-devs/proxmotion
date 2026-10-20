<?php

namespace Tests\Feature\Provisioning;

use App\Enums\OsPlatform;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskEstimates;
use App\Provisioning\TaskPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * What the server page shows of a task in progress (docs/adr/0032 §6): the
 * steps still to come, how long each finished step took, and how long a
 * create or reinstall still takes going by earlier runs with its template.
 */
class TaskProgressTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->buildInfrastructure();
        (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order());
    }

    /**
     * @param  array<string, int>  $durations  seconds per step, in order
     * @param  array<string, mixed>  $attributes
     */
    private function task(TaskType $type, TaskStatus $status, array $durations, array $attributes = []): Task
    {
        $at = Carbon::now()->subHour();
        $steps = [];

        foreach ($durations as $key => $seconds) {
            $steps[] = ['key' => $key, 'status' => 'done', 'message' => null, 'started_at' => $at->toIso8601String(), 'finished_at' => $at->addSeconds($seconds)->toIso8601String()];
        }

        $task = new Task;
        $task->forceFill([
            'server_id' => $this->server->id,
            'os_template_id' => $this->osTemplate->id,
            'type' => $type,
            'status' => $status,
            'steps' => $steps,
            ...$attributes,
        ])->save();

        return $task;
    }

    public function test_steps_record_when_they_started()
    {
        $task = $this->server->tasks()->sole();
        $task->forceFill(['steps' => [], 'started_at' => Carbon::now()])->save();

        Carbon::setTestNow('2026-10-20 10:00:30');
        $task->completeStep('clone');
        Carbon::setTestNow('2026-10-20 10:00:40');
        $task->startStep('wait_agent');
        Carbon::setTestNow('2026-10-20 10:01:40');
        $task->completeStep('wait_agent');

        $this->assertSame([
            ['clone', '2026-10-20T10:00:00+00:00', '2026-10-20T10:00:30+00:00'],
            ['wait_agent', '2026-10-20T10:00:40+00:00', '2026-10-20T10:01:40+00:00'],
        ], array_map(fn (array $step) => [$step['key'], $step['started_at'], $step['finished_at']], $task->steps));
    }

    public function test_a_create_plans_the_steps_that_apply_to_the_server()
    {
        $task = $this->server->tasks()->sole();
        $plan = app(TaskPlan::class);

        $this->assertSame(['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'verify'], $plan->steps($task));

        // Named from its location's template, on Windows: renamed and restarted.
        $this->server->forceFill(['vm_name' => '203.0.113.5'])->save();
        $this->server->osTemplate->platform = OsPlatform::Windows;
        $task->setRelation('server', $this->server);

        $this->assertSame(
            ['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'guest_hostname', 'reboot', 'verify'],
            $plan->steps($task),
        );

        $task->type = TaskType::Reinstall;
        $this->assertSame(['stop', 'destroy', 'clone'], array_slice($plan->steps($task), 0, 3));
    }

    public function test_the_estimate_uses_the_median_of_earlier_runs_with_the_same_template()
    {
        $this->server->tasks()->delete();
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 100, 'configure' => 10, 'start' => 20]);
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 120, 'configure' => 10, 'start' => 20]);
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 900, 'configure' => 10, 'start' => 20]);
        // Other templates, types and failed tasks do not count.
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 5000], ['os_template_id' => OsTemplate::factory()->create()->id]);
        $this->task(TaskType::Reinstall, TaskStatus::Succeeded, ['clone' => 5000]);
        $this->task(TaskType::Create, TaskStatus::Failed, ['clone' => 5000]);

        // Running for 30 s in its first step: 120 - 30 + 10 + 20.
        $running = $this->task(TaskType::Create, TaskStatus::Running, [], ['started_at' => Carbon::now()->subSeconds(30)]);

        $this->assertSame(
            ['remaining_seconds' => 120, 'based_on' => 3],
            app(TaskEstimates::class)->remaining($running, ['clone', 'configure', 'start']),
        );

        // Once clone and configure are done, 10 s ago: the start's 20 s less those 10.
        $running->forceFill(['steps' => [
            ['key' => 'clone', 'status' => 'done', 'message' => null, 'started_at' => Carbon::now()->subSeconds(40)->toIso8601String(), 'finished_at' => Carbon::now()->subSeconds(20)->toIso8601String()],
            ['key' => 'configure', 'status' => 'done', 'message' => null, 'started_at' => Carbon::now()->subSeconds(20)->toIso8601String(), 'finished_at' => Carbon::now()->subSeconds(10)->toIso8601String()],
        ]])->save();

        $this->assertSame(10, app(TaskEstimates::class)->remaining($running, ['clone', 'configure', 'start'])['remaining_seconds']);
    }

    public function test_too_little_history_or_a_finished_task_gives_no_estimate()
    {
        $this->server->tasks()->delete();
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 100]);
        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 100]);
        // Steps recorded before step times were kept give no durations.
        $this->task(TaskType::Create, TaskStatus::Succeeded, [])->forceFill(['steps' => [['key' => 'clone', 'status' => 'done', 'message' => null, 'finished_at' => Carbon::now()->toIso8601String()]]])->save();
        $running = $this->task(TaskType::Create, TaskStatus::Running, []);
        $estimates = app(TaskEstimates::class);

        $this->assertNull($estimates->remaining($running, ['clone']));

        $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 100]);
        $this->assertNotNull($estimates->remaining($running, ['clone']));

        $running->forceFill(['status' => TaskStatus::Succeeded])->save();
        $this->assertNull($estimates->remaining($running, ['clone']));
    }

    public function test_the_server_page_sends_planned_steps_and_the_estimate()
    {
        $this->server->tasks()->delete();

        foreach (range(1, 3) as $run) {
            $this->task(TaskType::Create, TaskStatus::Succeeded, ['clone' => 60, 'configure' => 10]);
        }

        $this->task(TaskType::Create, TaskStatus::Running, [], ['started_at' => Carbon::now()]);

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.status', 'running')
                ->where('tasks.0.planned_steps', ['clone', 'configure', 'resize', 'firewall', 'start', 'wait_agent', 'verify'])
                ->where('tasks.0.estimate', ['remaining_seconds' => 70, 'based_on' => 3])
                ->where('tasks.1.status', 'succeeded')
                ->where('tasks.1.planned_steps', [])
                ->where('tasks.1.estimate', null)
                ->where('tasks.1.steps.0.started_at', Carbon::now()->subHour()->toIso8601String()));
    }

    public function test_the_api_keeps_its_step_fields()
    {
        $task = $this->server->tasks()->sole();

        $this->assertArrayHasKey('started_at', $task->steps[0]);
        $this->assertSame(['key', 'status', 'message'], array_keys((new TaskResource($task))->toArray(request())['steps'][0]));
    }
}
