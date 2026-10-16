<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->customer)->for($this->node)->create(['vmid' => 100]);
        $this->server->forceFill(['root_password' => 'old-password-value'])->save();
    }

    private function reset(?User $user = null, string $route = 'servers.reset-password'): TestResponse
    {
        return $this->actingAs($user ?? $this->customer)
            ->from(route('servers.show', $this->server))
            ->post(route($route, $this->server));
    }

    public function test_customers_can_reset_the_root_password_through_the_guest_agent()
    {
        $this->reset()->assertRedirect(route('servers.show', $this->server));

        $this->server->refresh();
        $this->assertSame(TaskStatus::Succeeded, Task::query()->sole()->status);
        $this->assertNotSame('old-password-value', $this->server->root_password);
        $this->assertSame(20, strlen((string) $this->server->root_password));
        $this->assertSame($this->server->root_password, $this->proxmox->guestPasswords[100]['root']);
        $this->assertTrue($this->server->root_password_unrevealed);
    }

    public function test_the_cloud_init_password_is_left_alone()
    {
        $this->reset();

        $this->assertNotContains('PUT /nodes/pve1/qemu/100/config', $this->proxmox->writePaths());
    }

    public function test_the_reset_fails_cleanly_when_the_guest_agent_does_not_answer()
    {
        $this->proxmox->agentDown[100] = true;

        $this->reset();

        $task = Task::query()->sole();
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertStringContainsString('guest agent', (string) $task->error);
        $this->assertSame('old-password-value', $this->server->refresh()->root_password);
        $this->assertSame(ServerStatus::Active, $this->server->status);
    }

    public function test_resets_and_reveals_are_audited_without_the_password()
    {
        $this->reset();
        $this->actingAs($this->customer)->from(route('servers.show', $this->server))->post(route('servers.password', $this->server));

        $this->assertSame(['server.password_reset', 'server.password_revealed'], AuditLog::query()->orderBy('id')->pluck('action')->all());
        $password = (string) $this->server->refresh()->root_password;

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString($password, (string) json_encode($log->properties));
        }
    }

    public function test_the_owner_sees_a_new_password_only_once()
    {
        $this->server->forceFill(['root_password_unrevealed' => true])->save();

        $this->actingAs($this->customer)
            ->get(route('servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.password_unrevealed', true)
                ->where('revealedPassword', null))
            ->assertDontSee('old-password-value');

        $this->from(route('servers.show', $this->server))
            ->post(route('servers.password', $this->server))
            ->assertSessionHas('revealed_password', 'old-password-value');

        $this->assertFalse($this->server->refresh()->root_password_unrevealed);

        $this->post(route('servers.password', $this->server))->assertForbidden();
    }

    public function test_an_admin_looking_does_not_use_up_the_owners_view()
    {
        $this->server->forceFill(['root_password_unrevealed' => true])->save();

        $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
            ->post(route('admin.servers.password', $this->server))
            ->assertSessionHas('revealed_password', 'old-password-value');

        $this->assertTrue($this->server->refresh()->root_password_unrevealed);
    }

    public function test_new_servers_offer_their_initial_password_once()
    {
        (new FakeProxmoxCluster)->addTemplate(9000)->install();

        $server = app(ServerCreator::class)->create($this->order());

        $this->assertTrue($server->refresh()->root_password_unrevealed);
    }

    public function test_customers_cannot_reset_or_see_another_users_password()
    {
        $this->server->forceFill(['user_id' => User::factory()->create()->id, 'root_password_unrevealed' => true])->save();

        $this->reset()->assertForbidden();
        $this->actingAs($this->customer)->post(route('servers.password', $this->server))->assertForbidden();

        $this->assertSame(0, Task::query()->count());
        $this->assertTrue($this->server->refresh()->root_password_unrevealed);
    }

    public function test_suspended_servers_cannot_get_a_new_password()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->reset()->assertForbidden();

        $this->assertSame(0, Task::query()->count());
    }

    public function test_admins_can_reset_any_active_server()
    {
        $this->reset(User::factory()->admin()->withTwoFactor()->create(), 'admin.servers.reset-password');

        $this->assertSame($this->server->refresh()->root_password, $this->proxmox->guestPasswords[100]['root']);
    }

    public function test_customers_cannot_use_the_admin_routes()
    {
        $this->reset(route: 'admin.servers.reset-password')->assertForbidden();
        $this->actingAs($this->customer)->post(route('admin.servers.password', $this->server))->assertForbidden();
    }
}
