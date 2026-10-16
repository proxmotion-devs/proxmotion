<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;
use Proxmotion\Whmcs\Module;

/**
 * CreateAccount, Suspend, Unsuspend, Terminate, ChangePassword and the
 * connection test, against a faked Proxmotion API.
 */
class ProvisioningTest extends ModuleTestCase
{
    public function test_a_server_is_ordered_with_the_products_settings()
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));

        $this->assertSame('success', $this->module()->create());

        $request = $this->api->last('POST /servers');
        $this->assertSame([
            'external_id' => '42',
            'external_client_id' => '7',
            'package' => self::PACKAGE_ID,
            'location' => 'location-uuid',
            'os_template' => 'os-uuid',
            'hostname' => 'web1.example.com',
            'root_password' => 'a-long-whmcs-password',
        ], $request['body']);
        $this->assertSame('Bearer pmx_secret-token-value', $request['headers']['Authorization']);
        $this->assertRegExpCompat('/^whmcs-42-create-[0-9a-f]{16}$/', $request['headers']['Idempotency-Key']);
        $this->assertSame([], $this->localApiCalls(), 'The WHMCS password was used: nothing to store.');
    }

    public function test_configurable_options_and_the_ssh_key_field_override_the_product()
    {
        $this->serviceHas(null);
        $this->api->on('GET /packages', 200, ['data' => [
            ['id' => self::PACKAGE_ID, 'name' => 'VPS M', 'cpu_cores' => 2, 'memory_mib' => 4096, 'disk_mib' => 40960, 'ipv4_count' => 1, 'ipv6_count' => 0, 'traffic_bytes' => 1000000000000],
        ]]);
        $this->api->on('POST /servers', 202, $this->task('create'));

        $this->module([
            'configoption4' => 'routed',
            'configoptions' => [
                'os_template' => 'debian-uuid',
                'location' => '',
                'cpu_cores' => '4',
                'memory_gib' => '8',
                'extra_disk_gib' => '20',
                'extra_ipv4_count' => '2',
                'extra_traffic_gb' => '500',
                'extra_ipv6_count' => '0',
            ],
            'customfields' => ['ssh_key' => " ssh-ed25519 AAAAC3Nza user@example.com \n"],
        ])->create();

        $body = $this->api->last('POST /servers')['body'];
        $this->assertSame('debian-uuid', $body['os_template']);
        $this->assertSame('location-uuid', $body['location'], 'An empty option keeps the product setting.');
        $this->assertSame('routed', $body['network_model']);
        $this->assertSame(['ssh-ed25519 AAAAC3Nza user@example.com'], $body['ssh_keys']);
        $this->assertSame([
            'cpu_cores' => 4,
            'memory_mib' => 8192,
            'disk_mib' => 40960 + 20 * 1024,
            'ipv4_count' => 3,
            'traffic_bytes' => 1000000000000 + 500 * 1000000000,
        ], $body['resources']);
    }

    public function test_a_product_without_a_package_sends_its_resources_from_configurable_options()
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));

        $this->module([
            'configoption1' => 'none',
            'configoptions' => ['cpu_cores' => '4', 'memory_gib' => '8', 'disk_gib' => '100', 'ipv4_count' => '2'],
        ])->create();

        $body = $this->api->last('POST /servers')['body'];
        $this->assertNull($body['package']);
        $this->assertSame('bridged', $body['network_model'], 'Proxmotion needs a network model without a package.');
        $this->assertSame(['cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 102400, 'ipv4_count' => 2], $body['resources']);
        $this->assertNotContains('GET /packages', $this->api->sent());
    }

    public function test_extra_options_need_a_package()
    {
        $this->serviceHas(null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Configurable options starting with extra_ add to a package');

        $this->module(['configoption1' => 'none', 'configoptions' => ['cpu_cores' => '2', 'extra_ipv4_count' => '1']])->create();
    }

    public function test_the_package_list_offers_no_package()
    {
        $this->assertSame('none', \Proxmotion\Whmcs\ServerOrder::NO_PACKAGE);
        $this->assertStringContainsString('without a package', proxmotion_ConfigOptions()['Network model']['Options']['default']);
    }

    public function test_a_short_or_missing_whmcs_password_is_replaced_by_one_from_proxmotion()
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 200, ['data' => ['root_password' => 'from-proxmotion-1234']]);

        $this->module(['password' => 'short'])->create();

        $this->assertArrayNotHasKey('root_password', $this->api->last('POST /servers')['body']);
        $this->assertSame([['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'servicepassword' => 'from-proxmotion-1234']]], $this->localApiCalls());
        $this->assertLogDoesNotContain('from-proxmotion-1234');
    }

    public function test_an_invalid_domain_becomes_a_generated_hostname()
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));

        $this->module(['domain' => 'my_site!'])->create();

        $this->assertSame('vps42', $this->api->last('POST /servers')['body']['hostname']);
    }

    public function test_creating_a_service_that_already_has_a_server_changes_nothing()
    {
        $this->serviceHas($this->server());

        $this->assertSame('success', $this->module()->create());
        $this->assertSame(['GET /servers?external_id=42'], $this->api->sent());
    }

    public function test_a_lost_connection_is_retried_with_the_same_idempotency_key()
    {
        $this->serviceHas(null);
        $this->api->failOn('POST /servers')->on('POST /servers', 202, $this->task('create'));

        $this->assertSame('success', $this->module()->create());

        $creates = array_values(array_filter($this->api->requests, function ($request) {
            return $request['method'] === 'POST' && $request['path'] === '/servers';
        }));
        $this->assertCount(2, $creates);
        $this->assertSame($creates[0]['headers']['Idempotency-Key'], $creates[1]['headers']['Idempotency-Key']);
    }

    public function test_validation_errors_are_shown_to_the_admin()
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 422, [
            'message' => 'The hostname field must be a valid hostname.',
            'errors' => ['hostname' => ['The hostname field must be a valid hostname.']],
        ]);

        $this->assertSame(
            'The hostname field must be a valid hostname. (hostname: The hostname field must be a valid hostname.)',
            $this->runCommand('create')
        );
    }

    public function test_suspend_and_unsuspend()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/suspend', 202, $this->task('suspend'));

        $this->assertSame('success', $this->module(['suspendreason' => 'Overdue on Invoice #12'])->suspend());
        $this->assertSame(['reason' => 'Overdue on Invoice #12'], $this->api->last('POST /servers/' . self::SERVER_ID . '/suspend')['body']);

        $this->api = new FakeTransport();
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->api->on('POST /servers/' . self::SERVER_ID . '/unsuspend', 202, $this->task('unsuspend'));

        $this->assertSame('success', $this->module()->unsuspend());
        $this->assertContains('POST /servers/' . self::SERVER_ID . '/unsuspend', $this->api->sent());
    }

    public function test_suspend_and_unsuspend_are_no_ops_when_already_done()
    {
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->assertSame('success', $this->module()->suspend());

        $this->api = new FakeTransport();
        $this->serviceHas($this->server());
        $this->assertSame('success', $this->module()->unsuspend());

        $this->assertSame(['GET /servers?external_id=42'], $this->api->sent());
    }

    public function test_terminate()
    {
        $this->serviceHas($this->server());
        $this->api->on('DELETE /servers/' . self::SERVER_ID, 202, $this->task('terminate'));

        $this->assertSame('success', $this->module()->terminate());
        $this->assertContains('DELETE /servers/' . self::SERVER_ID, $this->api->sent());
    }

    public function test_terminating_a_service_without_a_server_succeeds()
    {
        $this->serviceHas(null);

        $this->assertSame('success', $this->module()->terminate());
    }

    public function test_change_password_sets_the_whmcs_password()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));

        $this->assertSame('success', $this->module(['password' => 'New-chosen-password-1'])->changePassword());

        $this->assertSame(['root_password' => 'New-chosen-password-1'], $this->api->last('POST /servers/' . self::SERVER_ID . '/password')['body']);
        $this->assertLogDoesNotContain('New-chosen-password-1');
    }

    public function test_change_password_refuses_short_passwords_and_inactive_servers()
    {
        $this->assertSame('The password must be at least 12 characters long.', $this->module(['password' => 'short'])->changePassword());

        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->assertSame('This is only possible while the server is active.', $this->runCommand('changePassword'));
    }

    public function test_change_package_sends_the_new_package_and_options()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /packages', 200, ['data' => [['id' => 'bigger-uuid', 'name' => 'VPS L', 'cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 81920, 'ipv4_count' => 1, 'ipv6_count' => 0]]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/package', 202, $this->task('change_resources'));

        $result = $this->module([
            'configoption1' => 'bigger-uuid',
            'configoption5' => 'on',
            'configoptions' => ['extra_memory_gib' => '2', 'extra_ipv4_count' => '1'],
        ])->changePackage();

        $this->assertSame('success', $result);
        $request = $this->api->last('POST /servers/' . self::SERVER_ID . '/package');
        $this->assertSame(['package' => 'bigger-uuid', 'resources' => ['memory_mib' => 10240, 'ipv4_count' => 2], 'reboot' => true], $request['body']);
        $this->assertSame(1, preg_match('/^whmcs-42-change-[0-9a-f]{16}$/', $request['headers']['Idempotency-Key']));
    }

    public function test_change_package_without_options_or_restart()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/package', 202, $this->task('change_resources'));

        $this->module(['configoption1' => 'none', 'configoptions' => ['cpu_cores' => '6']])->changePackage();

        $this->assertSame(['package' => null, 'resources' => ['cpu_cores' => 6], 'reboot' => false], $this->api->last('POST /servers/' . self::SERVER_ID . '/package')['body']);
    }

    public function test_change_package_shows_proxmotions_refusal()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/package', 422, ['message' => "The server's node does not have enough free capacity for these resources."]);

        $this->assertSame("The server's node does not have enough free capacity for these resources.", $this->runCommand('changePackage'));
    }

    public function test_the_connection_test_reads_the_catalog_and_servers()
    {
        $this->api->on('GET /packages', 200, ['data' => []])->on('GET /servers', 200, ['data' => []]);
        $this->assertSame(['success' => true, 'error' => ''], $this->module()->testConnection());

        $this->api = new FakeTransport();
        $this->api->on('GET /packages', 401, ['message' => 'Unauthenticated.']);
        $this->assertSame('Unauthenticated.', $this->runCommand('testConnection'));
    }

    public function test_the_token_is_never_logged()
    {
        $this->serviceHas($this->server());

        $this->module()->server();

        $this->assertNotEmpty($GLOBALS['whmcs_calls']['logModuleCall']);
        $this->assertLogDoesNotContain('pmx_secret-token-value');
    }

    public function test_the_panel_url_is_built_from_the_whmcs_server()
    {
        $this->assertSame('https://panel.example.com', Module::baseUrl($this->params()));
        $this->assertSame('https://panel.example.com:8443', Module::baseUrl($this->params(['serverport' => '8443'])));
        $this->assertSame('http://198.51.100.5', Module::baseUrl($this->params(['serverhostname' => '', 'serverip' => '198.51.100.5', 'serversecure' => ''])));
        $this->assertSame('https://panel.example.com', Module::baseUrl($this->params(['serverhostname' => 'https://panel.example.com/'])));
    }

    /**
     * Runs a module method like proxmotion_run() does: failures become their message.
     *
     * @param array<string, mixed> $params
     */
    private function runCommand(string $method, array $params = []): string
    {
        try {
            $result = $this->module($params)->$method();

            return is_array($result) ? 'success' : (string) $result;
        } catch (ApiException $e) {
            return $e->describe();
        }
    }

    private function assertLogDoesNotContain(string $secret): void
    {
        $this->assertStringNotContainsString($secret, (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']));
    }

    private function assertRegExpCompat(string $pattern, string $value): void
    {
        $this->assertSame(1, preg_match($pattern, $value), "{$value} does not match {$pattern}");
    }
}
