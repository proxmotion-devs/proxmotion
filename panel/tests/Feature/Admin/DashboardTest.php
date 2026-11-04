<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Location;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    public function test_it_says_which_setup_steps_are_done(): void
    {
        Location::factory()->create();

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.locations', true)
                ->where('setup.clusters', false)
                ->where('setup.billing_systems', false));
    }

    public function test_it_counts_the_servers_by_what_they_are_doing(): void
    {
        Server::factory()->count(2)->create();
        Server::factory()->create(['status' => ServerStatus::Suspended]);
        Server::factory()->create(['status' => ServerStatus::Provisioning]);
        Server::factory()->create(['status' => ServerStatus::Reinstalling]);
        Server::factory()->create(['status' => ServerStatus::Error]);
        Server::factory()->create(['status' => ServerStatus::Terminated]);

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Dashboard')
                ->where('servers', ['total' => 6, 'active' => 2, 'suspended' => 1, 'busy' => 2, 'error' => 1]));
    }

    public function test_it_lists_the_tasks_that_failed_in_the_last_24_hours(): void
    {
        $server = Server::factory()->create();
        $this->task($server, TaskStatus::Failed, now()->subHours(2), 'The guest agent did not answer.');
        $this->task($server, TaskStatus::Failed, now()->subDays(2), 'Too old to be listed.');
        $this->task($server, TaskStatus::Succeeded, now()->subHour());

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('failedTasks', 1)
                ->where('failedTasks.0.type', TaskType::Reinstall->value)
                ->where('failedTasks.0.error', 'The guest agent did not answer.')
                ->where('failedTasks.0.server.hostname', $server->hostname)
                ->where('failedTaskCount', 1));
    }

    private function task(Server $server, TaskStatus $status, \DateTimeInterface $finishedAt, ?string $error = null): Task
    {
        $task = new Task;
        $task->forceFill([
            'server_id' => $server->id,
            'type' => TaskType::Reinstall,
            'status' => $status,
            'steps' => [],
            'error' => $error,
            'finished_at' => $finishedAt,
        ])->save();

        return $task;
    }
}
