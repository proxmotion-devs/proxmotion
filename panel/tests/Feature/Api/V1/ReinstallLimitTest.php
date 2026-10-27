<?php

namespace Tests\Feature\Api\V1;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * How often a billing system may reinstall a server (docs/adr/0045): the
 * package's limit or the server's own, within a rolling window of days.
 */
class ReinstallLimitTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->server = $this->provisionedServer();
    }

    private function reinstall(): TestResponse
    {
        return $this->api('POST', "/api/v1/servers/{$this->server->uuid}/reinstall", ['os_template' => $this->osTemplate->uuid]);
    }

    private function limitPackage(?int $limit, ?int $days): void
    {
        $this->package->forceFill(['reinstall_limit' => $limit, 'reinstall_period_days' => $days])->save();
    }

    private function adminReinstall(): TestResponse
    {
        return $this->actingAs(User::factory()->withTwoFactor()->create())
            ->post(route('admin.servers.reinstall', $this->server), ['os_template' => $this->osTemplate->uuid, 'confirm_hostname' => $this->server->hostname]);
    }

    public function test_the_package_limits_reinstalls_within_its_window()
    {
        $this->limitPackage(2, 30);

        $this->reinstall()->assertAccepted();
        $this->travel(5)->days();
        $this->reinstall()->assertAccepted();
        $this->travel(1)->days();

        $tasks = Task::query()->where('type', TaskType::Reinstall)->count();
        $this->reinstall()
            ->assertConflict()
            ->assertJsonPath('error', 'reinstall_limit_reached')
            // The first reinstall leaves the window 30 days after it started.
            ->assertJsonPath('available_at', now()->subDays(6)->addDays(30)->toIso8601String())
            ->assertJsonPath('message', 'The reinstall limit of this server (2 in 30 days) has been reached. It can be reinstalled again after '.now()->subDays(6)->addDays(30)->utc()->format('Y-m-d H:i').' UTC.');
        $this->assertSame($tasks, Task::query()->where('type', TaskType::Reinstall)->count(), 'A refused reinstall changes nothing.');

        $this->api('GET', "/api/v1/servers/{$this->server->uuid}")
            ->assertOk()
            ->assertJsonPath('data.reinstalls', [
                'limit' => 2,
                'period_days' => 30,
                'used' => 2,
                'remaining' => 0,
                'available_at' => now()->subDays(6)->addDays(30)->toIso8601String(),
            ]);

        $this->travel(24)->days();
        $this->reinstall()->assertAccepted();
        $this->api('GET', "/api/v1/servers/{$this->server->uuid}")->assertJsonPath('data.reinstalls.used', 2)->assertJsonPath('data.reinstalls.remaining', 0);
    }

    public function test_a_limit_of_zero_allows_no_reinstall_through_the_api()
    {
        $this->limitPackage(0, 30);

        $this->reinstall()
            ->assertConflict()
            ->assertExactJson([
                'message' => 'This server cannot be reinstalled through the billing system.',
                'error' => 'reinstall_limit_reached',
                'available_at' => null,
            ]);
    }

    public function test_administrators_are_never_limited_and_do_not_use_up_reinstalls()
    {
        $this->limitPackage(1, 30);

        $this->adminReinstall()->assertRedirect();
        $this->adminReinstall()->assertRedirect();
        $this->assertSame(2, Task::query()->where('type', TaskType::Reinstall)->whereNotNull('user_id')->count(), 'Both reinstalls of the administrator ran.');

        $this->reinstall()->assertAccepted();
        $this->reinstall()->assertConflict();

        $this->limitPackage(0, 30);
        $this->adminReinstall()->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_failed_reinstalls_do_not_count()
    {
        $this->limitPackage(1, 30);

        $failed = new Task;
        $failed->forceFill([
            'server_id' => $this->server->id,
            'api_token_id' => $this->token->id,
            'type' => TaskType::Reinstall,
            'status' => TaskStatus::Failed,
            'steps' => [],
        ])->save();

        $this->reinstall()->assertAccepted();
    }

    public function test_unlimited_servers_say_so_and_lists_do_not_count()
    {
        $this->api('GET', "/api/v1/servers/{$this->server->uuid}")
            ->assertJsonPath('data.reinstalls', ['limit' => null, 'period_days' => null, 'used' => null, 'remaining' => null, 'available_at' => null]);

        $this->api('GET', '/api/v1/servers')->assertOk()->assertJsonMissingPath('data.0.reinstalls');

        $this->limitPackage(3, 7);
        $this->api('GET', '/api/v1/packages')->assertJsonPath('data.0.reinstall_limit', ['count' => 3, 'period_days' => 7]);
        $this->limitPackage(null, null);
        $this->api('GET', '/api/v1/packages')->assertJsonPath('data.0.reinstall_limit', null);
    }

    public function test_a_servers_own_limit_replaces_the_packages()
    {
        $this->limitPackage(0, 30);
        $admin = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)
            ->put(route('admin.servers.reinstall-limit', $this->server), ['custom' => true, 'reinstall_limit' => 1, 'reinstall_period_days' => 7])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->server->refresh();
        $this->assertTrue($this->server->reinstall_limit_custom);
        $this->assertSame(1, $this->server->reinstall_limit);
        $this->assertSame(7, $this->server->reinstall_period_days);
        $log = AuditLog::query()->where('action', 'server.reinstall_limit_changed')->sole();
        $this->assertSame(['hostname' => $this->server->hostname, 'custom' => true, 'limit' => 1, 'period_days' => 7], $log->properties);

        $this->reinstall()->assertAccepted();
        $this->reinstall()->assertConflict();

        // Its own limit left empty: unlimited, whatever the package says.
        $this->actingAs($admin)
            ->put(route('admin.servers.reinstall-limit', $this->server), ['custom' => true, 'reinstall_limit' => null, 'reinstall_period_days' => null])
            ->assertSessionHasNoErrors();
        $this->reinstall()->assertAccepted();

        // Back to the package's.
        $this->actingAs($admin)
            ->put(route('admin.servers.reinstall-limit', $this->server), ['custom' => false, 'reinstall_limit' => 5, 'reinstall_period_days' => 5])
            ->assertSessionHasNoErrors();
        $this->server->refresh();
        $this->assertFalse($this->server->reinstall_limit_custom);
        $this->assertNull($this->server->reinstall_limit);
        $this->reinstall()->assertConflict();
    }

    public function test_a_limit_needs_its_window()
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->put(route('admin.servers.reinstall-limit', $this->server), ['custom' => true, 'reinstall_limit' => 2])
            ->assertSessionHasErrors(['reinstall_period_days']);

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->put(route('admin.servers.reinstall-limit', $this->server), ['custom' => true, 'reinstall_limit' => 2, 'reinstall_period_days' => 366])
            ->assertSessionHasErrors(['reinstall_period_days']);
    }

    public function test_resetting_the_count_allows_reinstalls_again()
    {
        $this->limitPackage(1, 30);
        $this->reinstall()->assertAccepted();
        $this->travel(1)->minutes();

        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reinstallLimit.source', 'package')
                ->where('reinstallLimit.limit', 1)
                ->where('reinstallLimit.used', 1)
                ->where('reinstallLimit.remaining', 0)
                ->where('reinstallLimit.package_limit', 1)
                ->where('reinstallLimit.has_package', true)
                ->where('reinstallLimit.reset_at', null));

        $this->actingAs($admin)->post(route('admin.servers.reinstall-limit.reset', $this->server))->assertRedirect();
        $this->assertNotNull($this->server->refresh()->reinstalls_reset_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'server.reinstalls_reset')->count());

        $this->travel(1)->minutes();
        $this->reinstall()->assertAccepted();
        $this->reinstall()->assertConflict();
    }
}
