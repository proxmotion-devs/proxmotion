<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use Tests\Concerns\BuildsInfrastructure;
use Tests\TestCase;

/**
 * A task whose queue job never ran (or died) must not block its server
 * forever: the job's failed() ends it, and administrators can end it by hand.
 */
class StuckTaskTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->buildInfrastructure();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(TaskType $type, TaskStatus $status, array $attributes = []): Task
    {
        $task = new Task;
        $task->forceFill(array_replace([
            'server_id' => $this->server->id,
            'type' => $type,
            'status' => $status,
            'steps' => [],
        ], $attributes))->save();

        return $task;
    }

    public function test_a_failed_job_ends_its_queued_task_and_frees_the_server()
    {
        $task = $this->task(TaskType::Shutdown, TaskStatus::Queued);

        (new RunServerTask($task))->failed(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertStringContainsString('PDOException', (string) $task->error);
        $this->assertNotNull($task->finished_at);
        $this->assertSame(ServerStatus::Active, $this->server->refresh()->status, 'A power task leaves the server as it was.');

        app(TaskRunner::class)->start($this->server, TaskType::Start);
        $this->assertSame(2, $this->server->tasks()->count(), 'The server takes operations again.');
    }

    public function test_a_failed_create_job_leaves_the_server_in_error_so_it_can_be_retried()
    {
        $this->server->forceFill(['status' => ServerStatus::Pending])->save();
        $task = $this->task(TaskType::Create, TaskStatus::Running, ['started_at' => now()]);

        (new RunServerTask($task))->failed(null);

        $this->assertSame(TaskStatus::Failed, $task->refresh()->status);
        $this->assertSame(ServerStatus::Error, $this->server->refresh()->status);
    }

    public function test_a_failed_job_never_changes_a_finished_task()
    {
        $task = $this->task(TaskType::Reboot, TaskStatus::Succeeded, ['finished_at' => now()]);

        (new RunServerTask($task))->failed(new \RuntimeException('late'));

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status);
        $this->assertNull($task->error);
    }

    public function test_the_job_skips_a_task_an_administrator_already_ended()
    {
        $task = $this->task(TaskType::Shutdown, TaskStatus::Failed, ['finished_at' => now()]);

        dispatch_sync(new RunServerTask($task));

        $this->assertSame(TaskStatus::Failed, $task->refresh()->status, 'Proxmox is not asked (stray requests fail the test).');
    }

    public function test_admins_can_mark_a_stuck_task_as_failed()
    {
        $task = $this->task(TaskType::Shutdown, TaskStatus::Queued);

        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.tasks.fail', $task))
            ->assertRedirect(route('admin.servers.show', $this->server));

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('Marked as failed by an administrator.', $task->error);

        $log = AuditLog::query()->where('action', 'task.marked_failed')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['task' => $task->uuid, 'type' => 'shutdown', 'status' => 'queued'], $log->properties);
    }

    public function test_only_queued_tasks_and_tasks_running_past_their_time_limit_can_be_marked_failed()
    {
        $running = $this->task(TaskType::Reinstall, TaskStatus::Running, ['started_at' => now()->subMinutes(5)]);
        $finished = $this->task(TaskType::Reboot, TaskStatus::Succeeded, ['finished_at' => now()]);

        foreach ([$running, $finished] as $task) {
            $this->actingAs($this->admin)->post(route('admin.tasks.fail', $task));
            $this->assertNotSame(TaskStatus::Failed, $task->refresh()->status);
        }

        $running->forceFill(['started_at' => now()->subSeconds(RunServerTask::TIMEOUT + 60)])->save();
        $this->actingAs($this->admin)->post(route('admin.tasks.fail', $running));

        $this->assertSame(TaskStatus::Failed, $running->refresh()->status);
        $this->assertSame(ServerStatus::Error, $this->server->refresh()->status, 'A reinstall that never finished leaves the server in error.');
        $this->assertSame(0, AuditLog::query()->where('action', 'task.marked_failed')->where('properties->task', $finished->uuid)->count());
    }

    public function test_the_server_page_offers_the_button_only_for_stuck_tasks()
    {
        $this->task(TaskType::Reboot, TaskStatus::Succeeded, ['finished_at' => now()]);
        $this->task(TaskType::Shutdown, TaskStatus::Queued);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.status', 'queued')
                ->where('tasks.0.can_fail', true)
                ->where('tasks.1.can_fail', false));
    }

    public function test_guests_cannot_mark_tasks_failed()
    {
        $task = $this->task(TaskType::Shutdown, TaskStatus::Queued);

        $this->post(route('admin.tasks.fail', $task))->assertRedirect(route('login'));
        $this->assertSame(TaskStatus::Queued, $task->refresh()->status);
    }
}
