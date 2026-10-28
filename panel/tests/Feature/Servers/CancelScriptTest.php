<?php

namespace Tests\Feature\Servers;

use App\Domain\Servers\GuestScripts;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\RunServerTask;
use App\Models\AuditLog;
use App\Models\CustomScript;
use App\Models\GuestScriptLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use App\Provisioning\TaskFailer;
use App\Provisioning\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Administrators stop their own runs of a script (guest_script tasks): the
 * task fails at once, and the job running it stops the script and what it
 * started inside the server.
 */
class CancelScriptTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    private CustomScript $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();

        $this->script = new CustomScript;
        $this->script->forceFill([
            'os_template_id' => $this->osTemplate->id,
            'name' => 'Rearm',
            'manual' => true,
            'events' => [],
            'position' => 1,
            'content' => "slmgr /rearm\n",
        ])->save();
    }

    public function test_a_queued_or_running_script_run_can_be_cancelled_and_frees_the_server()
    {
        Queue::fake([RunServerTask::class]);
        $task = app(TaskRunner::class)->start($this->server, TaskType::GuestScript, $this->admin, input: ['script' => $this->script->uuid, 'manual' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.can_cancel', true));

        $this->actingAs($this->admin)
            ->post(route('admin.tasks.cancel', $task))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(GuestScripts::CANCELLED, $task->error);
        $audit = AuditLog::query()->where('action', 'task.cancelled')->sole();
        $this->assertSame($task->uuid, $audit->properties['task']);
        $this->assertSame('queued', $audit->properties['status']);

        app(TaskRunner::class)->start($this->server, TaskType::Start);
        $this->assertSame(1, $this->server->tasks()->where('type', TaskType::Start)->count(), 'The server takes operations again.');
    }

    public function test_only_script_runs_can_be_cancelled()
    {
        Queue::fake([RunServerTask::class]);
        $task = app(TaskRunner::class)->start($this->server, TaskType::Reboot, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.0.can_cancel', false));

        $this->actingAs($this->admin)->post(route('admin.tasks.cancel', $task))->assertRedirect();

        $this->assertSame(TaskStatus::Queued, $task->refresh()->status);
        $this->assertFalse(AuditLog::query()->where('action', 'task.cancelled')->exists());
    }

    public function test_a_finished_run_can_no_longer_be_cancelled()
    {
        $task = app(TaskRunner::class)->start($this->server, TaskType::GuestScript, $this->admin, input: ['script' => $this->script->uuid, 'manual' => true])->refresh();
        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);

        $this->actingAs($this->admin)->post(route('admin.tasks.cancel', $task))->assertRedirect();

        $this->assertSame(TaskStatus::Succeeded, $task->refresh()->status);
    }

    public function test_a_cancelled_linux_run_is_stopped_inside_the_server_by_its_marker()
    {
        Sleep::fake();
        $path = "/usr/local/sbin/proxmotion-script-{$this->script->id}";
        $this->proxmox->execHandler = fn (array $command) => in_array($path, $command, true) ? [0, "half done\n", ''] : [0, '', ''];
        $this->hangUntilCancelled($path);

        $task = app(TaskRunner::class)->start($this->server, TaskType::GuestScript, $this->admin, input: ['script' => $this->script->uuid, 'manual' => true])->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(GuestScripts::CANCELLED, $task->error, 'The administrator\'s cancel stays the task\'s outcome.');
        $log = GuestScriptLog::query()->sole();
        $this->assertContains(['env', "PROXMOTION_SCRIPT_RUN={$log->id}", 'sh', $path], $this->commands());
        $this->assertContains(['sh', '-c', GuestScripts::LINUX_STOP_SCRIPT, 'proxmotion-stop', (string) $log->id], $this->commands());
        $this->assertSame('failed', $log->status);
        $this->assertSame(GuestScripts::CANCELLED, $log->message);
        $this->assertSame("half done\n", $log->stdout, 'What it printed until then is kept.');
        $this->assertNotNull($log->finished_at);
    }

    public function test_a_cancelled_windows_run_is_stopped_with_the_processes_it_started()
    {
        Sleep::fake();
        $this->osTemplate->forceFill(['distribution' => 'windows'])->save();
        $path = "C:\\ProgramData\\Proxmotion\\script-{$this->script->id}.ps1";
        $pid = $this->hangUntilCancelled($path);

        $task = app(TaskRunner::class)->start($this->server, TaskType::GuestScript, $this->admin, input: ['script' => $this->script->uuid, 'manual' => true])->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $stop = collect($this->commands())->first(fn (array $command) => ($command[3] ?? null) === '-Command');
        $this->assertNotNull($stop, 'A stop script ran.');
        $this->assertSame(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command'], array_slice($stop, 0, 4));
        $this->assertStringContainsString('$tree.Add('.$pid->value.')', $stop[4], 'It stops the run\'s process tree.');
        $log = GuestScriptLog::query()->sole();
        $this->assertSame(1, $log->exit_code);
        $this->assertSame(GuestScripts::CANCELLED, $log->message);
    }

    public function test_scripts_run_during_operations_are_not_marked_or_cancellable()
    {
        $this->script->forceFill(['events' => ['password_reset']])->save();

        $task = app(TaskRunner::class)->start($this->server, TaskType::ResetPassword, $this->admin, secret: 'chosen-password-123')->refresh();

        $this->assertSame(TaskStatus::Succeeded, $task->status, (string) $task->error);
        $this->assertContains(['sh', "/usr/local/sbin/proxmotion-script-{$this->script->id}"], $this->commands(), 'Only an administrator\'s own runs carry the marker.');
    }

    /**
     * The command running $path hangs until a stop command ends it; on its
     * first poll its task is cancelled as TaskController::cancel() does.
     * The returned object gets the hanging command's pid.
     */
    private function hangUntilCancelled(string $path): object
    {
        $pid = (object) ['value' => null];
        $this->proxmox->hangs = fn (array $command) => in_array($path, $command, true);
        $this->proxmox->whileHanging = function (int $hanging) use ($pid) {
            $pid->value = $hanging;
            $task = Task::query()->where('type', TaskType::GuestScript)->sole();

            if ($task->status === TaskStatus::Running) {
                app(TaskFailer::class)->fail($task, GuestScripts::CANCELLED, alert: false);
            }
        };

        return $pid;
    }

    /**
     * @return list<list<string>>
     */
    private function commands(): array
    {
        return array_column($this->proxmox->guestCommands, 'command');
    }
}
