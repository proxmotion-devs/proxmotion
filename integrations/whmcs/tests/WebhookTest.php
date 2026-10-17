<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiClient;
use Proxmotion\Whmcs\Module;
use Proxmotion\Whmcs\WebhookReceiver;
use Proxmotion\Whmcs\WebhookSignature;

/**
 * webhook.php: signature, repeats and what each event changes in WHMCS,
 * with the WHMCS database and the Proxmotion API faked.
 */
class WebhookTest extends ModuleTestCase
{
    const SECRET = 'whsec_0123456789abcdefghijklmnopqrstuvwxyzABCD';

    const NOW = 1800000000;

    /** @var FakeWebhookStore */
    private $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new FakeWebhookStore();
        $this->store->servers = [
            ['serverid' => 3, 'serverhostname' => 'panel.example.com', 'serversecure' => true, 'serverport' => '', 'serverpassword' => 'pmx_secret-token-value', 'serveraccesshash' => self::SECRET],
            ['serverid' => 4, 'serverhostname' => 'other.example.com', 'serversecure' => true, 'serverport' => '', 'serverpassword' => 'pmx_other', 'serveraccesshash' => ''],
        ];
        $this->store->services[42] = ['id' => 42, 'userid' => 7, 'domain' => 'web1.example.com', 'status' => 'Active', 'server' => 3];
    }

    public function test_a_ping_with_a_valid_signature_is_answered()
    {
        $response = $this->deliver('ping', ['billing_system' => ['id' => 'b', 'name' => 'WHMCS']]);

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $this->api->requests);
        $log = $GLOBALS['whmcs_calls']['logModuleCall'];
        $this->assertCount(1, $log);
        $this->assertSame('webhook ping', $log[0]['action']);
        $this->assertStringStartsWith('200', $log[0]['response']);
    }

    public function test_a_wrong_signature_is_refused()
    {
        $body = $this->body('ping', []);
        $response = $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header('whsec_wrong', $body, self::NOW)], $body);

        $this->assertSame(401, $response['status']);
        $this->assertSame([], $this->store->events, 'A forged event is not remembered.');
        $this->assertSame('webhook rejected', $GLOBALS['whmcs_calls']['logModuleCall'][0]['action']);
    }

    public function test_the_signing_secret_never_reaches_the_module_log()
    {
        $body = $this->body('ping', ['note' => self::SECRET]);
        $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header('whsec_wrong', $body, self::NOW)], $body);
        $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW)], $body);

        foreach ($GLOBALS['whmcs_calls']['logModuleCall'] as $entry) {
            $this->assertStringNotContainsString(self::SECRET, (string) $entry['request']);
            $this->assertStringNotContainsString(self::SECRET, (string) $entry['response']);
        }
    }

    public function test_a_changed_body_is_refused()
    {
        $body = $this->body('ping', []);
        $header = WebhookSignature::header(self::SECRET, $body, self::NOW);

        $this->assertSame(401, $this->receiver()->handle('POST', ['proxmotion-signature' => $header], $body . ' ')['status']);
    }

    public function test_old_and_future_timestamps_are_refused()
    {
        $body = $this->body('ping', []);

        foreach ([self::NOW - 301, self::NOW + 301] as $timestamp) {
            $response = $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, $timestamp)], $body);
            $this->assertSame(400, $response['status']);
        }

        $response = $this->receiver()->handle('POST', ['proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW - 299)], $body);
        $this->assertSame(200, $response['status']);
    }

    public function test_missing_or_malformed_headers_and_other_methods_are_refused()
    {
        $body = $this->body('ping', []);

        $this->assertSame(400, $this->receiver()->handle('POST', [], $body)['status']);
        $this->assertSame(400, $this->receiver()->handle('POST', ['proxmotion-signature' => 'v1=abc'], $body)['status']);
        $this->assertSame(400, $this->receiver()->handle('POST', ['proxmotion-signature' => 't=' . self::NOW], $body)['status']);
        $this->assertSame(405, $this->receiver()->handle('GET', [], '')['status']);
    }

    public function test_any_of_several_signatures_may_match()
    {
        $body = $this->body('ping', []);
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',' . substr(WebhookSignature::header(self::SECRET, $body, self::NOW), strlen('t=' . self::NOW . ','));

        $this->assertSame(200, $this->receiver()->handle('POST', ['proxmotion-signature' => $header], $body)['status']);
    }

    public function test_a_repeated_event_is_processed_once()
    {
        $this->store->services[42]['status'] = 'Pending';
        $this->serviceHas($this->server(['hostname' => 'web1.example.com']));

        $first = $this->deliver('server.provisioned', $this->data(), 'event-1');
        $second = $this->deliver('server.provisioned', $this->data(), 'event-1');

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame('Already received.', $second['body']);
        $this->assertCount(1, $this->localApiCalls());
        $this->assertCount(1, $this->api->requests);
    }

    public function test_provisioned_activates_a_pending_service_and_takes_the_hostname()
    {
        $this->store->services[42]['status'] = 'Pending';
        $this->store->services[42]['domain'] = '';
        $this->serviceHas($this->server(['hostname' => 'vps42.example.com']));

        $this->assertSame(200, $this->deliver('server.provisioned', $this->data())['status']);

        $this->assertSame([
            ['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'status' => 'Active', 'domain' => 'vps42.example.com']],
        ], $this->localApiCalls());
        $this->assertSame('Bearer pmx_secret-token-value', $this->api->requests[0]['headers']['Authorization'], 'The token of the WHMCS server whose secret matched.');
    }

    public function test_provisioned_changes_nothing_for_an_active_service_with_the_hostname()
    {
        $this->serviceHas($this->server());

        $this->deliver('server.provisioned', $this->data());

        $this->assertSame([], $this->localApiCalls());
    }

    public function test_a_server_suspended_in_the_panel_suspends_the_service()
    {
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->deliver('server.suspended', $this->data(['status' => 'suspended']));

        $calls = $this->localApiCalls();
        $this->assertSame(['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'status' => 'Suspended']], $calls[0]);
        $this->assertSame('LogActivity', $calls[1]['command']);
        $this->assertSame(7, $calls[1]['values']['userid']);
    }

    public function test_a_late_suspended_event_after_an_unsuspend_changes_nothing()
    {
        // The unsuspend finished first; the suspended event came late, from a retry.
        $this->serviceHas($this->server(['status' => 'active']));

        $response = $this->deliver('server.suspended', $this->data(['status' => 'suspended']));

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $this->localApiCalls());
    }

    public function test_a_suspension_started_by_whmcs_changes_nothing_more()
    {
        $this->store->services[42]['status'] = 'Suspended';
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->deliver('server.suspended', $this->data(['status' => 'suspended']));

        $this->assertSame([], $this->localApiCalls());
    }

    public function test_a_server_unsuspended_in_the_panel_reactivates_the_service()
    {
        $this->store->services[42]['status'] = 'Suspended';
        $this->serviceHas($this->server(['status' => 'active']));

        $this->deliver('server.unsuspended', $this->data());

        $this->assertSame(['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'status' => 'Active']], $this->localApiCalls()[0]);
    }

    public function test_a_termination_notifies_the_administrators_but_keeps_the_service()
    {
        $this->deliver('server.terminated', $this->data(['status' => 'terminated']));

        $calls = $this->localApiCalls();
        $this->assertSame('SendAdminEmail', $calls[0]['command']);
        $this->assertStringContainsString('#42', $calls[0]['values']['customsubject']);
        $this->assertSame('LogActivity', $calls[1]['command']);
        $this->assertCount(2, $calls, 'The service status is not changed.');
        $this->assertSame([], $this->api->requests);
    }

    public function test_a_termination_started_by_whmcs_notifies_nobody()
    {
        $this->store->services[42]['status'] = 'Terminated';

        $this->deliver('server.terminated', $this->data(['status' => 'terminated']));

        $this->assertSame([], $this->localApiCalls());
    }

    public function test_a_failure_notifies_the_administrators()
    {
        $this->deliver('server.failed', $this->data(['status' => 'error'], ['status' => 'failed', 'error' => 'No node has enough free memory.']));

        $calls = $this->localApiCalls();
        $this->assertSame('SendAdminEmail', $calls[0]['command']);
        $this->assertSame('account', $calls[0]['values']['type']);
        $this->assertStringContainsString('No node has enough free memory.', $calls[0]['values']['custommessage']);
        $this->assertSame('LogActivity', $calls[1]['command']);
    }

    public function test_task_events_are_only_logged()
    {
        $this->deliver('task.succeeded', $this->data());
        $this->deliver('server.reinstalled', $this->data());

        $this->assertSame([], $this->localApiCalls());
        $this->assertSame(['webhook task.succeeded', 'webhook server.reinstalled'], array_column($GLOBALS['whmcs_calls']['logModuleCall'], 'action'));
    }

    public function test_events_of_unknown_services_or_other_panels_are_ignored()
    {
        $this->store->services[43] = ['id' => 43, 'userid' => 8, 'domain' => 'x.example.com', 'status' => 'Active', 'server' => 4];

        $this->assertSame(200, $this->deliver('server.failed', $this->data(['external_id' => '99']))['status']);
        $this->assertSame(200, $this->deliver('server.failed', $this->data(['external_id' => '43']))['status']);
        $this->assertSame(200, $this->deliver('server.failed', $this->data(['external_id' => null]))['status']);

        $this->assertSame([], $this->localApiCalls());
    }

    public function test_an_unreachable_api_is_retried_later()
    {
        $this->store->services[42]['status'] = 'Pending';
        $this->api->on('GET /servers?external_id=42', 503, ['message' => 'Down']);

        $response = $this->deliver('server.provisioned', $this->data(), 'event-2');

        $this->assertSame(503, $response['status']);
        $this->assertSame([], $this->store->events, 'The event can be processed when Proxmotion sends it again.');
        $this->assertSame([], $this->localApiCalls());
    }

    public function test_a_signing_secret_in_the_access_hash_is_not_used_as_the_token()
    {
        $this->api->on('GET /packages', 200, ['data' => []]);
        $this->api->on('GET /servers', 200, ['data' => []]);

        $module = Module::fromParams($this->params(['serverpassword' => '', 'serveraccesshash' => self::SECRET]), $this->api);
        $module->testConnection();

        $this->assertSame('Bearer ', $this->api->requests[0]['headers']['Authorization']);
    }

    private function receiver(): WebhookReceiver
    {
        $api = $this->api;

        return new WebhookReceiver($this->store, function (array $params) use ($api) {
            return new ApiClient(Module::baseUrl($params) . '/api/v1', (string) $params['serverpassword'], $api);
        }, function () {
            return self::NOW;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: string}
     */
    private function deliver(string $event, array $data, ?string $id = null): array
    {
        $body = $this->body($event, $data, $id);

        return $this->receiver()->handle('POST', [
            'proxmotion-signature' => WebhookSignature::header(self::SECRET, $body, self::NOW),
            'proxmotion-webhook-event' => $event,
        ], $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function body(string $event, array $data, ?string $id = null): string
    {
        return (string) json_encode([
            'id' => $id !== null ? $id : bin2hex(random_bytes(8)),
            'event' => $event,
            'created_at' => '2027-01-15T08:00:00+00:00',
            'data' => $data,
        ]);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function data(array $server = [], array $task = []): array
    {
        return [
            'server' => array_replace(['id' => self::SERVER_ID, 'external_id' => '42', 'external_client_id' => '7', 'hostname' => 'web1.example.com', 'status' => 'active'], $server),
            'task' => $this->task('create', 'succeeded', $task)['data'],
        ];
    }
}
