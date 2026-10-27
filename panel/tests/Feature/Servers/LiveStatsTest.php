<?php

namespace Tests\Feature\Servers;

use App\Domain\Usage\DiskSpace;
use App\Domain\Usage\LiveStats;
use App\Enums\ServerStatus;
use App\Events\ServerStatsUpdated;
use App\Models\Server;
use App\Models\User;
use App\Support\Broadcasting\ReverbFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * Live figures of the server page (docs/adr/0036).
 */
class LiveStatsTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Rates divide by the milliseconds between two samples.
        $this->freezeTime();
        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->proxmox->stats[100] = $this->sample(netin: 1_000_000, netout: 500_000, diskread: 0, diskwrite: 0, uptime: 3600);
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @return array<string, int|float>
     */
    private function sample(int $netin, int $netout, int $diskread, int $diskwrite, int $uptime): array
    {
        return [
            'cpu' => 0.256,
            'cpus' => 2,
            'mem' => 1024 ** 3,
            'maxmem' => 2 * 1024 ** 3,
            'netin' => $netin,
            'netout' => $netout,
            'diskread' => $diskread,
            'diskwrite' => $diskwrite,
            'uptime' => $uptime,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function stats(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin)->getJson(route('admin.servers.stats', ['server' => $this->server, ...$query]));
    }

    private function statusRequests(): int
    {
        return Http::recorded(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/qemu/100/status/current'))->count();
    }

    private function fsinfoRequests(): int
    {
        return Http::recorded(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/qemu/100/agent/get-fsinfo'))->count();
    }

    public function test_the_page_gets_the_servers_figures_without_rates_at_first()
    {
        $this->stats()
            ->assertOk()
            ->assertJsonPath('stats.status', 'running')
            ->assertJsonPath('stats.cpu_percent', 25.6)
            ->assertJsonPath('stats.cpus', 2)
            ->assertJsonPath('stats.memory_bytes', 1024 ** 3)
            ->assertJsonPath('stats.memory_total_bytes', 2 * 1024 ** 3)
            ->assertJsonPath('stats.uptime', 3600)
            ->assertJsonPath('stats.net_in', null)
            ->assertJsonPath('history', []);
    }

    public function test_rates_come_from_two_samples()
    {
        $this->stats();
        $this->travel(20)->seconds();
        $this->proxmox->stats[100] = $this->sample(netin: 3_000_000, netout: 1_500_000, diskread: 4_000_000, diskwrite: 2_000_000, uptime: 3620);

        $this->stats()
            ->assertJsonPath('stats.net_in', 100000)
            ->assertJsonPath('stats.net_out', 50000)
            ->assertJsonPath('stats.disk_read', 200000)
            ->assertJsonPath('stats.disk_write', 100000);
    }

    public function test_a_restart_gives_no_rates_rather_than_negative_ones()
    {
        $this->stats();
        $this->travel(20)->seconds();
        // The VM started again: its counters and uptime start from zero.
        $this->proxmox->stats[100] = $this->sample(netin: 2_000_000, netout: 100, diskread: 10, diskwrite: 10, uptime: 15);

        $this->stats()
            ->assertJsonPath('stats.uptime', 15)
            ->assertJsonPath('stats.net_in', null)
            ->assertJsonPath('stats.net_out', null)
            ->assertJsonPath('stats.disk_read', null);
    }

    public function test_a_fresh_sample_is_answered_without_asking_proxmox_again()
    {
        $this->stats();
        $this->travel(LiveStats::FRESH_SECONDS - 5)->seconds();
        $this->stats()->assertJsonPath('stats.cpu_percent', 25.6);

        $this->assertSame(1, $this->statusRequests());

        $this->travel(10)->seconds();
        $this->stats();

        $this->assertSame(2, $this->statusRequests());
    }

    public function test_the_last_hour_comes_with_history()
    {
        $this->proxmox->rrd[100] = [
            ['time' => 1_790_000_060, 'cpu' => 0.5, 'mem' => 1024 ** 3, 'netin' => 1250.5, 'netout' => 10, 'diskread' => 0, 'diskwrite' => 4096],
            ['time' => 1_790_000_000, 'cpu' => 0.1, 'mem' => 512 * 1024 ** 2, 'netin' => 100, 'netout' => 20, 'diskread' => 0, 'diskwrite' => 0],
            // A minute without data (the VM was stopped).
            ['time' => 1_790_000_120],
        ];

        $this->stats(['history' => 1])
            ->assertJsonCount(3, 'history')
            ->assertJsonPath('history.0.time', 1_790_000_000_000)
            ->assertJsonPath('history.0.cpu_percent', 10)
            ->assertJsonPath('history.1.net_in', 1250.5)
            ->assertJsonPath('history.1.memory_bytes', 1024 ** 3)
            ->assertJsonPath('history.2.cpu_percent', null);

        $this->assertTrue(Http::recorded(fn ($request) => str_contains($request->url(), '/qemu/100/rrddata?timeframe=hour&cf=AVERAGE'))->isNotEmpty());
    }

    public function test_servers_without_a_vm_are_never_asked_for()
    {
        $this->server->forceFill(['status' => ServerStatus::Provisioning])->save();

        $this->stats(['history' => 1])->assertOk()->assertExactJson(['stats' => null, 'disk' => null, 'history' => []]);

        $this->assertSame(0, $this->statusRequests());
        $this->assertCount(0, app(LiveStats::class)->watched());
    }

    public function test_figures_are_null_when_proxmox_does_not_answer()
    {
        unset($this->proxmox->vms[100]);

        $this->stats(['history' => 1])->assertOk()->assertJsonPath('stats', null)->assertJsonPath('history', []);
    }

    public function test_disk_space_comes_from_the_guest_agent_once_a_minute()
    {
        $this->proxmox->guestFileSystems[100] = [
            ['name' => 'sda2', 'mountpoint' => '/home', 'type' => 'ext4', 'used-bytes' => 1024 ** 3, 'total-bytes' => 4 * 1024 ** 3],
            ['name' => 'sda1', 'mountpoint' => '/', 'type' => 'ext4', 'used-bytes' => 3 * 1024 ** 3, 'total-bytes' => 12 * 1024 ** 3],
        ];

        $this->stats()
            ->assertOk()
            ->assertJsonPath('disk.available', true)
            ->assertJsonPath('disk.file_systems', [
                ['mountpoint' => '/', 'type' => 'ext4', 'used_bytes' => 3 * 1024 ** 3, 'total_bytes' => 12 * 1024 ** 3],
                ['mountpoint' => '/home', 'type' => 'ext4', 'used_bytes' => 1024 ** 3, 'total_bytes' => 4 * 1024 ** 3],
            ]);

        $this->travel(DiskSpace::FRESH_SECONDS - 10)->seconds();
        $this->stats()->assertJsonPath('disk.available', true);
        $this->assertSame(1, $this->fsinfoRequests());

        $this->travel(20)->seconds();
        $this->stats();
        $this->assertSame(2, $this->fsinfoRequests());
    }

    public function test_disk_space_is_unknown_while_the_guest_agent_does_not_answer()
    {
        $this->proxmox->agentDown[100] = true;

        $this->stats()
            ->assertOk()
            ->assertJsonPath('stats.status', 'running')
            ->assertJsonPath('disk.available', false)
            ->assertJsonPath('disk.file_systems', []);

        // Not asked again for a minute.
        $this->travel(LiveStats::FRESH_SECONDS + 1)->seconds();
        $this->stats()->assertJsonPath('disk.available', false);
        $this->assertSame(1, $this->fsinfoRequests());
    }

    public function test_the_guest_agent_of_a_stopped_vm_is_not_asked()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->stats()->assertOk()->assertJsonPath('stats.status', 'stopped')->assertJsonPath('disk', null);

        $this->assertSame(0, $this->fsinfoRequests());
    }

    public function test_only_administrators_get_figures()
    {
        $this->getJson(route('admin.servers.stats', $this->server))->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.servers.stats', $this->server))
            ->assertRedirect(route('security.edit'));

        $this->assertSame(0, $this->statusRequests());
    }

    public function test_asking_marks_the_server_as_watched_for_a_while()
    {
        $stats = app(LiveStats::class);
        $this->assertCount(0, $stats->watched());

        $this->stats();
        $this->assertTrue($stats->watched()->first()?->is($this->server));

        $this->travel(LiveStats::WATCH_SECONDS + 1)->seconds();
        $this->assertCount(0, $stats->watched());
    }

    public function test_the_command_broadcasts_the_figures_of_watched_servers_only()
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([ServerStatsUpdated::class]);
        $this->proxmox->addVm(101, 'web2.example.com');
        Server::factory()->for($this->node)->create(['vmid' => 101]);

        $this->stats();
        $this->travel(10)->seconds();

        $this->artisan('servers:stats')->assertSuccessful();

        Event::assertDispatchedTimes(ServerStatsUpdated::class, 1);
        Event::assertDispatched(function (ServerStatsUpdated $event) {
            return $event->serverUuid === $this->server->uuid
                && $event->broadcastOn()->name === "private-admin.servers.{$this->server->uuid}"
                && $event->broadcastAs() === 'stats.updated'
                && $event->broadcastWith()['cpu_percent'] === 25.6;
        });
        $this->assertFalse(Http::recorded(fn ($request) => str_contains($request->url(), '/qemu/101/'))->isNotEmpty());
    }

    public function test_the_command_skips_a_cluster_that_cannot_be_reached()
    {
        config(['broadcasting.default' => 'reverb']);
        Event::fake([ServerStatsUpdated::class]);
        $this->stats();
        $this->travel(20)->seconds();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('servers:stats')->assertSuccessful();

        Event::assertNotDispatched(ServerStatsUpdated::class);
    }

    public function test_the_command_does_nothing_without_a_websocket_server()
    {
        Event::fake([ServerStatsUpdated::class]);
        $this->stats();
        $requests = $this->statusRequests();

        $this->artisan('servers:stats')->assertSuccessful();

        Event::assertNotDispatched(ServerStatsUpdated::class);
        $this->assertSame($requests, $this->statusRequests());
    }

    public function test_reverb_without_its_settings_falls_back_to_the_log_broadcaster()
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.secret' => '',
        ]);

        ReverbFallback::apply(config());

        $this->assertSame('log', config('broadcasting.default'));
        $this->assertSame(['REVERB_APP_KEY', 'REVERB_APP_SECRET'], ReverbFallback::missing(config()));

        Log::spy();
        Event::fake([ServerStatsUpdated::class]);
        $this->stats();

        $this->artisan('servers:stats')
            ->expectsOutputToContain('REVERB_APP_KEY, REVERB_APP_SECRET are not set')
            ->assertSuccessful();
        $this->artisan('servers:stats')->assertSuccessful();

        Event::assertNotDispatched(ServerStatsUpdated::class);
        // Once an hour in the log, though the command runs every 10 seconds.
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_configured_reverb_is_kept()
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
        ]);

        ReverbFallback::apply(config());

        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame([], ReverbFallback::missing(config()));
    }

    public function test_the_servers_channel_is_open_to_administrators_with_two_factor_only()
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // Channels are registered on the broadcaster in use when the app boots (null in tests).
        Broadcast::purge();
        require base_path('routes/channels.php');

        $body = ['socket_id' => '1234.5678', 'channel_name' => "private-admin.servers.{$this->server->uuid}"];

        $this->actingAs($this->admin)->postJson('/broadcasting/auth', $body)
            ->assertOk()
            ->assertJsonPath('auth', fn (string $auth) => str_starts_with($auth, 'test-key:'));

        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', $body)
            ->assertRedirect(route('security.edit'));

        $this->actingAs($this->admin)->postJson('/broadcasting/auth', [...$body, 'channel_name' => 'private-admin.servers.00000000-0000-0000-0000-000000000000'])
            ->assertForbidden();
    }

    public function test_guests_cannot_join_a_servers_channel()
    {
        $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-admin.servers.{$this->server->uuid}"])
            ->assertUnauthorized();
    }
}
