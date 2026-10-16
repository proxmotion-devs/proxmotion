<?php

namespace Tests\Feature\Servers;

use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private const SECRET = 'test-console-proxy-secret';

    private FakeProxmoxCluster $proxmox;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['proxmotion.console.proxy_secret' => self::SECRET]);

        $this->buildInfrastructure();
        $this->proxmox = (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100]);
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    public function test_admins_can_open_the_console()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.servers.console', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('console/Show')
                ->where('server.uuid', $this->server->uuid)
                ->where('console.password', 'fake-vnc-password')
                ->where('error', null)
                ->where('backUrl', route('admin.servers.show', $this->server))
                ->where('console.url', fn (string $url) => preg_match('#^ws://[^/]+/console-ws\?ticket=[A-Za-z0-9]{64}$#', $url) === 1));

        $this->assertContains('POST /nodes/pve1/qemu/100/vncproxy', $this->proxmox->writePaths());

        $log = AuditLog::query()->sole();
        $this->assertSame('console.opened', $log->action);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertTrue($log->subject?->is($this->server));
    }

    public function test_the_websocket_url_can_be_configured()
    {
        config(['proxmotion.console.websocket_url' => 'wss://console.example.com/ws/']);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.console', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('console.url', fn (string $url) => str_starts_with($url, 'wss://console.example.com/ws?ticket=')));
    }

    public function test_the_page_never_contains_the_vnc_ticket_or_the_api_token()
    {
        $response = $this->actingAs($this->admin)->get(route('admin.servers.console', $this->server));

        $response->assertDontSee('fake-ticket');
        $response->assertDontSee($this->cluster->token_secret);
    }

    public function test_the_console_is_not_available_for_suspended_servers()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.servers.console', $this->server))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.servers.console', $this->server))->assertRedirect(route('login'));
    }

    public function test_a_stopped_vm_shows_an_error_instead_of_a_console()
    {
        $this->proxmox->vms[100]['status'] = 'stopped';

        $this->actingAs($this->admin)
            ->get(route('admin.servers.console', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('console', null)
                ->where('error', 'The console could not be opened. Make sure the server is running.'));

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_the_proxy_redeems_a_ticket_exactly_once()
    {
        $ticket = $this->openConsole();

        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], $this->proxyHeaders())
            ->assertOk()
            ->assertJsonPath('server', $this->server->uuid)
            ->assertJsonPath('upstream.authorization', $this->cluster->connection()->authorizationHeader())
            ->assertJsonPath('upstream.verify_tls', true)
            ->assertJsonPath('upstream.urls.0', 'wss://'.$this->cluster->hosts[0].':8006/api2/json/nodes/pve1/qemu/100/vncwebsocket?port=5900&vncticket=PVEVNC%3A100%3Afake-ticket');

        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], $this->proxyHeaders())
            ->assertNotFound();
    }

    public function test_tickets_expire()
    {
        $ticket = $this->openConsole();

        $this->travel(16)->seconds();

        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], $this->proxyHeaders())
            ->assertNotFound();
    }

    public function test_internal_endpoints_require_the_proxy_secret()
    {
        $ticket = $this->openConsole();

        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket])->assertUnauthorized();
        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], ['X-Console-Proxy-Secret' => 'wrong'])->assertUnauthorized();

        config(['proxmotion.console.proxy_secret' => '']);
        $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], ['X-Console-Proxy-Secret' => ''])->assertStatus(503);
    }

    public function test_heartbeat_keeps_the_newest_session_of_an_active_server()
    {
        $session = $this->redeem($this->openConsole())['session'];

        $this->assertTrue($this->heartbeat($session));
        $this->assertFalse($this->heartbeat('unknown-session'));
    }

    public function test_opening_a_new_console_ends_the_previous_session()
    {
        $first = $this->redeem($this->openConsole())['session'];
        $second = $this->redeem($this->openConsole())['session'];

        $this->assertFalse($this->heartbeat($first));
        $this->assertTrue($this->heartbeat($second));
    }

    public function test_suspending_the_server_ends_the_session()
    {
        $session = $this->redeem($this->openConsole())['session'];

        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->assertFalse($this->heartbeat($session));
    }

    public function test_sessions_end_after_the_maximum_duration()
    {
        config(['proxmotion.console.max_minutes' => 60]);
        $session = $this->redeem($this->openConsole())['session'];

        $this->travel(61)->minutes();

        $this->assertFalse($this->heartbeat($session));
    }

    private function openConsole(): string
    {
        $page = $this->actingAs($this->admin)
            ->get(route('admin.servers.console', $this->server))
            ->assertOk()
            ->viewData('page');

        parse_str((string) parse_url((string) Arr::get($page, 'props.console.url'), PHP_URL_QUERY), $query);

        return (string) Arr::get($query, 'ticket');
    }

    /**
     * @return array<string, mixed>
     */
    private function redeem(string $ticket): array
    {
        return $this->postJson(route('internal.console.redeem'), ['ticket' => $ticket], $this->proxyHeaders())
            ->assertOk()
            ->json();
    }

    private function heartbeat(string $session): bool
    {
        return $this->postJson(route('internal.console.heartbeat'), ['session' => $session], $this->proxyHeaders())
            ->assertOk()
            ->json('active');
    }

    /**
     * @return array<string, string>
     */
    private function proxyHeaders(): array
    {
        return ['X-Console-Proxy-Secret' => self::SECRET];
    }
}
