<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Wisecp\ApiClient;
use Proxmotion\Wisecp\WebhookReceiver;
use Proxmotion\Wisecp\WebhookSignature;
use WISECP\Modules\Servers\Proxmotion;

/**
 * The webhook receiver: signature, repeats and what each event changes in
 * WISECP, with WISECP and the Proxmotion API faked.
 */
final class WebhookTest extends ModuleTestCase
{
    private const SECRET = 'whsec_0123456789abcdefghijklmnopqrstuvwxyzABCD';

    private const NOW = 1800000000;

    private FakeWebhookStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeWebhookStore;
        $this->store->servers = [
            $this->wisecpServer(['id' => 2, 'fields' => ['api_token' => 'pmx_other', 'webhook_secret' => 'whsec_another-billing-systems-secret']]),
            $this->wisecpServer(['fields' => ['api_token' => self::TOKEN, 'webhook_secret' => self::SECRET]]),
        ];
        $this->store->services[42] = ['id' => 42, 'owner_id' => 7, 'status' => 'active', 'options' => ['hostname' => 'web1.example.com', 'ip' => '203.0.113.10'], 'server' => 3];
    }

    public function test_a_ping_is_answered(): void
    {
        $this->assertSame(['status' => 200, 'body' => 'Pong.'], $this->send('ping'));
        $this->assertSame([], $this->api->sent());
    }

    public function test_an_invalid_signature_is_refused_without_logging_the_body(): void
    {
        $body = $this->event('server.suspended');

        $response = $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header('whsec_wrong', $body, self::NOW)], $body);

        $this->assertSame(401, $response['status']);
        $this->assertSame([], $this->store->changes);
        $this->assertSame([], $this->store->events);
        $this->assertSame('', $this->store->logs[0]['request']);
    }

    public function test_old_missing_and_malformed_signatures_are_refused(): void
    {
        $body = $this->event('ping');

        $this->assertSame(400, $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW - 301)], $body)['status']);
        $this->assertSame(400, $this->receiver()->handle('POST', [], $body)['status']);
        $this->assertSame(400, $this->receiver()->handle('POST', ['proxmotion-signature' => 'v1=abc'], $body)['status']);
        $this->assertSame(405, $this->receiver()->handle('GET', [], '')['status']);
    }

    public function test_a_signed_body_that_is_no_event_is_refused(): void
    {
        $body = '{"hello":"world"}';

        $this->assertSame(400, $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW)], $body)['status']);
    }

    public function test_a_repeated_event_is_dropped(): void
    {
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->assertSame(200, $this->send('server.suspended')['status']);
        $this->assertSame(['status' => 200, 'body' => 'Already received.'], $this->send('server.suspended'));
        $this->assertCount(2, $this->store->changes, 'Changed once: status and history.');
    }

    public function test_a_provisioned_server_updates_the_services_hostname_and_addresses(): void
    {
        $this->store->services[42]['status'] = 'inprocess';
        $this->serviceHas($this->server(['hostname' => 'web9.example.com']));

        $this->assertSame('Updated: hostname, assigned_ips, status.', $this->send('server.provisioned')['body']);
        $this->assertSame([
            'updateOptions 42 {"hostname":"web9.example.com","ip":"203.0.113.10","assigned_ips":"2001:db8::10"}',
            'changeStatus 42 active',
        ], $this->store->changes);
    }

    public function test_a_provisioned_event_for_a_server_no_longer_active_changes_nothing(): void
    {
        $this->serviceHas($this->server(['status' => 'reinstalling']));

        $this->assertSame('Nothing changed: the server is no longer active.', $this->send('server.provisioned')['body']);
        $this->assertSame([], $this->store->changes);
    }

    public function test_a_suspension_in_the_panel_suspends_the_service(): void
    {
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->assertSame('Status set to suspended.', $this->send('server.suspended')['body']);
        $this->assertSame('changeStatus 42 suspended (Suspended in Proxmotion)', $this->store->changes[0]);
    }

    public function test_a_late_suspension_event_is_not_applied_after_an_unsuspension(): void
    {
        $this->serviceHas($this->server(['status' => 'active']));

        $this->assertSame('Nothing changed: the server is no longer suspended.', $this->send('server.suspended')['body']);
        $this->assertSame([], $this->store->changes);
    }

    public function test_an_unsuspension_in_the_panel_reactivates_the_service(): void
    {
        $this->store->services[42]['status'] = 'suspended';
        $this->serviceHas($this->server());

        $this->assertSame('Status set to active.', $this->send('server.unsuspended')['body']);
        $this->assertSame('changeStatus 42 active', $this->store->changes[0]);
    }

    public function test_a_termination_only_tells_the_administrators(): void
    {
        $this->assertSame('Administrators notified.', $this->send('server.terminated')['body']);
        $this->assertStringStartsWith('notifyAdmins 42 The server web1.example.com of service #42 was terminated in Proxmotion.', $this->store->changes[0]);
        $this->assertStringStartsWith('history 42', $this->store->changes[1]);
        $this->assertSame([], $this->api->sent());
    }

    public function test_a_failed_task_tells_the_administrators_with_the_error(): void
    {
        $this->send('server.failed', ['error' => 'Clone failed: storage full']);

        $this->assertStringContainsString('Error: Clone failed: storage full', $this->store->changes[0]);
    }

    public function test_task_events_are_only_logged(): void
    {
        $this->assertSame('Logged.', $this->send('task.succeeded')['body']);
        $this->assertSame([], $this->store->changes);
        $this->assertSame('webhook task.succeeded', $this->store->logs[0]['action']);
    }

    public function test_services_of_other_servers_are_ignored(): void
    {
        $this->store->services[42]['server'] = 2;

        $this->assertStringStartsWith('Ignored: service #42', $this->send('server.suspended')['body']);
        $this->assertSame([], $this->store->changes);
    }

    public function test_an_unreachable_api_is_answered_with_503_and_the_event_retried(): void
    {
        $this->api->failOn('GET /servers?external_id=42');

        $this->assertSame(503, $this->send('server.suspended')['status']);
        $this->assertSame([], $this->store->events, 'The event id was released.');

        $this->setUp();
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->assertSame(200, $this->send('server.suspended')['status']);
    }

    public function test_the_signing_secret_is_masked_in_the_log(): void
    {
        $this->send('server.failed', ['error' => 'leaked ' . self::SECRET]);

        $this->assertStringNotContainsString(self::SECRET, json_encode($this->store->logs) ?: '');
    }

    public function test_hooks_register_the_public_webhook_route(): void
    {
        \Hook::reset();
        require PROXMOTION_WISECP_MODULE . '/hooks.php';

        $routes = [];
        $audience = 'module';
        (\Hook::$listeners[0])($routes, $audience);
        $this->assertSame([['POST', 'proxmotion/webhook', 'Module:Servers/Proxmotion', 'webhook', true]], $routes);

        $adminRoutes = [];
        $admin = 'admin';
        (\Hook::$listeners[0])($adminRoutes, $admin);
        $this->assertSame([], $adminRoutes);
    }

    public function test_the_signing_secret_field_is_read_from_the_server(): void
    {
        $this->assertSame(self::SECRET, Proxmotion::field($this->store->servers[1], 'webhook_secret'));
        $this->assertSame('', Proxmotion::field(['fields' => 'not decoded'], 'webhook_secret'));
    }

    /**
     * Send a signed event about service 42.
     *
     * @param array<string, mixed> $task
     * @return array{status: int, body: string}
     */
    private function send(string $event, array $task = []): array
    {
        $body = $this->event($event, $task);

        return $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW)], $body);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function event(string $event, array $task = []): string
    {
        return (string) json_encode([
            'id' => 'evt-' . $event,
            'event' => $event,
            'created_at' => '2027-01-15T08:00:00Z',
            'data' => [
                'server' => ['id' => self::SERVER_ID, 'external_id' => '42', 'external_client_id' => '7', 'hostname' => 'web1.example.com', 'status' => 'active'],
                'task' => array_replace(['id' => 'task-uuid', 'type' => 'create', 'status' => 'succeeded', 'error' => null], $task),
            ],
        ]);
    }

    private function receiver(): WebhookReceiver
    {
        return new WebhookReceiver(
            $this->store,
            fn (array $server): ApiClient => new ApiClient(Proxmotion::baseUrl($server) . '/api/v1', Proxmotion::field($server, 'api_token'), $this->api),
            static fn (): int => self::NOW,
        );
    }
}
