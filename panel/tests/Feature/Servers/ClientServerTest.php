<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\TestCase;

class ClientServerTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->server = Server::factory()->for($this->customer)->for($this->node)->create([
            'hostname' => 'web1.example.com',
            'vmid' => 4242,
        ]);
        $this->ipv4Pool->addresses()->firstOrFail()->forceFill(['server_id' => $this->server->id])->save();
    }

    public function test_customers_see_the_details_of_their_own_server()
    {
        $this->actingAs($this->customer)
            ->get(route('servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/servers/Show')
                ->where('server.uuid', $this->server->uuid)
                ->where('server.hostname', 'web1.example.com')
                ->where('server.location', $this->location->name)
                ->where('server.addresses.0.version', 4)
                ->missing('server.vmid')
                ->missing('server.node')
                ->missing('server.cluster')
                ->missing('server.storage')
                ->missing('server.user'));
    }

    public function test_internal_details_are_not_sent_to_customers()
    {
        $task = new Task;
        $task->forceFill([
            'server_id' => $this->server->id,
            'type' => TaskType::Create,
            'status' => TaskStatus::Failed,
            'steps' => [['key' => 'clone', 'status' => 'done', 'message' => 'internal-node-detail', 'finished_at' => now()->toIso8601String()]],
            'error' => 'storage internal-storage-detail is full',
        ])->save();

        $this->actingAs($this->customer)
            ->get(route('servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.steps.0', ['key' => 'clone', 'status' => 'done'])
                ->missing('tasks.0.error'))
            ->assertDontSee('internal-node-detail')
            ->assertDontSee('internal-storage-detail');
    }

    public function test_customers_cannot_see_another_users_server()
    {
        $other = Server::factory()->for($this->node)->create();

        $this->actingAs($this->customer)
            ->get(route('servers.show', $other))
            ->assertForbidden();
    }

    public function test_customers_cannot_see_their_terminated_servers()
    {
        $this->server->forceFill(['status' => ServerStatus::Terminated])->save();

        $this->actingAs($this->customer)
            ->get(route('servers.show', $this->server))
            ->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login_page()
    {
        $this->get(route('servers.show', $this->server))->assertRedirect(route('login'));
    }

    public function test_admins_are_sent_to_the_admin_server_page()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('servers.show', $this->server))
            ->assertRedirect(route('admin.servers.show', $this->server));
    }

    public function test_the_dashboard_lists_the_customers_servers_page_by_page()
    {
        Server::factory()->count(12)->for($this->customer)->for($this->node)->create();
        Server::factory()->for($this->node)->create(['hostname' => 'theirs.example.com']);
        Server::factory()->for($this->customer)->for($this->node)->status(ServerStatus::Terminated)->create(['hostname' => 'gone.example.com']);

        $this->actingAs($this->customer)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Dashboard')
                ->where('servers.total', 13)
                ->has('servers.data', 12))
            ->assertDontSee('theirs.example.com')
            ->assertDontSee('gone.example.com');

        $this->actingAs($this->customer)
            ->get(route('dashboard', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('servers.data', 1));
    }
}
