<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Servers\ConsoleSessions;
use App\Enums\ServerStatus;
use App\Models\AuditLog;
use App\Models\Server;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Console links for customers (docs/adr/0008): signed, single-use, valid for
 * 60 seconds, bound to one server; the browser session keeps a grant for
 * reconnects that ends with the token or the server's active state.
 */
class ConsoleLinkTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->provisionedServer();
    }

    private function issueLink(?Server $server = null): string
    {
        $server ??= $this->server;

        return (string) $this->api('POST', "/api/v1/servers/{$server->uuid}/console")
            ->assertCreated()
            ->assertJsonStructure(['data' => ['url', 'expires_at']])
            ->json('data.url');
    }

    private function assertConsoleOpens(): void
    {
        $this->get(route('console.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('console/Show')
                ->where('server.uuid', $this->server->uuid)
                ->where('console.password', 'fake-vnc-password')
                ->where('error', null)
                ->where('backUrl', null));
    }

    private function assertConsoleRefused(string $error): void
    {
        $this->get(route('console.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('console', null)->where('error', $error));
    }

    public function test_a_link_opens_the_console_of_its_server_without_a_panel_login()
    {
        $link = $this->issueLink();
        $this->assertStringContainsString("/console/{$this->server->uuid}/link/", $link);
        $this->assertStringContainsString('signature=', $link);

        $this->get($link)->assertRedirect(route('console.show', $this->server));
        $this->assertConsoleOpens();
        $this->assertGuest();

        // Reconnecting (a page reload) works with the grant of this browser session.
        $this->assertConsoleOpens();

        $this->assertSame(1, AuditLog::query()->where('action', 'console.link_issued')->where('api_token_id', $this->token->id)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'console.opened')->where('api_token_id', $this->token->id)->count());
    }

    public function test_a_link_works_only_once()
    {
        $link = $this->issueLink();
        $this->get($link)->assertRedirect();

        $this->flushSession();
        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('console', null)
                ->where('error', 'This console link has already been used or has expired. Open the console again from your client area.'));
    }

    public function test_a_link_expires_after_60_seconds()
    {
        $link = $this->issueLink();

        $this->travel(61)->seconds();

        $this->get($link)->assertForbidden();
    }

    public function test_a_tampered_link_or_one_moved_to_another_server_is_refused()
    {
        $other = $this->provisionedServer('whmcs-other');
        $link = $this->issueLink();

        $this->get($link.'x')->assertForbidden();
        $this->get(str_replace($this->server->uuid, $other->uuid, $link))->assertForbidden();
    }

    public function test_the_grant_covers_only_the_linked_server()
    {
        $other = $this->provisionedServer('whmcs-other');
        $this->get($this->issueLink())->assertRedirect();

        $this->get(route('console.show', $other))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('console', null)
                ->where('error', 'This console session has ended. Open the console again from your client area.'));
    }

    public function test_the_console_page_needs_a_grant()
    {
        $this->assertConsoleRefused('This console session has ended. Open the console again from your client area.');
    }

    public function test_revoking_the_token_ends_the_grant_and_the_running_session()
    {
        $this->get($this->issueLink())->assertRedirect();
        $this->assertConsoleOpens();

        $this->token->forceFill(['revoked_at' => now()])->save();

        $this->assertConsoleRefused('This console session has ended. Open the console again from your client area.');
    }

    public function test_the_grant_ends_after_the_maximum_session_length()
    {
        config(['proxmotion.console.max_minutes' => 60]);
        $this->get($this->issueLink())->assertRedirect();

        $this->travel(61)->minutes();

        $this->assertConsoleRefused('This console session has ended. Open the console again from your client area.');
    }

    public function test_a_suspended_server_has_no_console()
    {
        $this->get($this->issueLink())->assertRedirect();
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->assertConsoleRefused('The console is only available while the server is active.');
    }

    public function test_sessions_opened_through_a_link_end_with_the_token()
    {
        $sessions = app(ConsoleSessions::class);
        $ticket = $sessions->open($this->server, $this->token);
        $session = $sessions->redeem($ticket->ticket)['session'] ?? null;
        $this->assertIsString($session);

        $this->assertTrue($sessions->isActive($session));

        $this->token->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse($sessions->isActive($session));
    }
}
