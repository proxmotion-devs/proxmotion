<?php

namespace Tests\Integration;

use App\Domain\Servers\ConsoleSessions;
use App\Enums\TaskType;
use App\Models\User;
use Tests\Integration\Concerns\ManagesTestServers;

/**
 * The panel's side of the console (ADR 0002) on a real VM: vncproxy answers
 * with a port, ticket and password, and a console ticket redeems once into
 * vncwebsocket URLs for every cluster host. The WebSocket itself is checked
 * by hand through deploy/console-proxy.
 */
class ConsoleTicketTest extends IntegrationTestCase
{
    use ManagesTestServers;

    public function test_a_console_ticket_is_issued_for_a_running_server()
    {
        $server = $this->adoptTestVm('proxmotion-console-test');
        $this->runTask($server, TaskType::Start);

        $vnc = $this->client()->qemu($server->node->name)->vncProxy($server->vmid);
        $this->assertGreaterThan(0, $vnc->port);

        $sessions = app(ConsoleSessions::class);
        $ticket = $sessions->open($server, User::factory()->create());
        $this->assertNotSame('', $ticket->password);

        $upstream = $sessions->redeem($ticket->ticket);
        $this->assertNotNull($upstream);
        $this->assertNull($sessions->redeem($ticket->ticket), 'A console ticket worked twice.');

        $urls = $upstream['upstream']['urls'] ?? [];
        $this->assertCount(count($this->cluster->hosts), $urls);

        foreach ($urls as $url) {
            $this->assertStringStartsWith('wss://', $url);
            $this->assertStringContainsString("/nodes/{$server->node->name}/qemu/{$server->vmid}/vncwebsocket?port=", $url);
            $this->assertStringContainsString('vncticket=', $url);
        }
    }
}
