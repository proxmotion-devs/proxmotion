<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Exception;
use Proxmotion\Wisecp\ApiException;
use WISECP\Modules\Servers\Proxmotion;

/**
 * create, suspend, unsuspend, cancel, upgrade, change_password, the
 * connection test and the product settings, against a faked Proxmotion API.
 */
final class ProvisioningTest extends ModuleTestCase
{
    public function test_a_server_is_ordered_with_the_products_settings(): void
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $result = $this->module(['login' => ['username' => 'root', 'password' => 'encrypted:a-long-wisecp-password']])->create();

        $request = $this->api->last('POST /servers');
        $this->assertSame([
            'external_id' => '42',
            'external_client_id' => '7',
            'package' => self::PACKAGE_ID,
            'location' => 'location-uuid',
            'os_template' => 'os-uuid',
            'hostname' => 'web1.example.com',
            'root_password' => 'a-long-wisecp-password',
        ], $request['body']);
        $this->assertSame('https://panel.example.com/api/v1/servers', $request['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $request['headers']['Authorization']);
        $this->assertMatchesRegularExpression('/^wisecp-42-create-[0-9a-f]{16}$/', $request['headers']['Idempotency-Key']);
        $this->assertSame([
            'config' => ['server_id' => self::SERVER_ID],
            'login' => ['username' => 'root', 'password' => 'encrypted:a-long-wisecp-password'],
            'hostname' => 'web1.example.com',
            'ip' => '203.0.113.10',
            'assigned_ips' => '2001:db8::10',
        ], $result);
    }

    public function test_order_answers_replace_the_products_settings(): void
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $this->module([], [
            'os_template' => 'other-os',
            'location' => 'other-location',
            'hostname' => 'Chosen.Example.com',
            'ssh_key' => 'ssh-ed25519 AAAAC3Nza test@example.com',
            'root_password' => 'the-customers-password',
        ])->create();

        $body = $this->api->body('POST /servers');
        $this->assertSame('other-os', $body['os_template']);
        $this->assertSame('other-location', $body['location']);
        $this->assertSame('chosen.example.com', $body['hostname']);
        $this->assertSame(['ssh-ed25519 AAAAC3Nza test@example.com'], $body['ssh_keys']);
        $this->assertSame('the-customers-password', $body['root_password']);
    }

    public function test_without_a_hostname_a_location_with_a_hostname_template_names_the_server(): void
    {
        $this->serviceHas(null);
        $this->api->on('GET /locations', 200, ['data' => [['id' => 'location-uuid', 'name' => 'Istanbul', 'generates_hostnames' => true]]]);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server(['hostname' => 'ip-203-0-113-10.example.com'])]);

        $result = $this->module(['hostname' => '', 'login' => ['password' => 'encrypted:a-long-wisecp-password']])->create();

        $this->assertArrayNotHasKey('hostname', $this->api->body('POST /servers'));
        $this->assertSame('ip-203-0-113-10.example.com', $result['hostname']);
    }

    public function test_add_ons_add_to_the_packages_values(): void
    {
        $this->serviceHas(null);
        $this->api->on('GET /packages', 200, ['data' => [['id' => self::PACKAGE_ID, 'cpu_cores' => 2, 'memory_mib' => 2048, 'disk_mib' => 20480, 'ipv4_count' => 1]]]);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $this->module(['login' => ['password' => 'encrypted:a-long-wisecp-password']], [], [
            'extra_memory_gib' => 2,
            'extra_ipv4_count' => 1,
            'extra_disk_gib' => 0,
        ])->create();

        $this->assertSame(['memory_mib' => 4096, 'ipv4_count' => 2], $this->api->body('POST /servers')['resources']);
    }

    public function test_without_a_package_the_products_resources_are_sent_with_the_bridged_model(): void
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $this->module(['login' => ['password' => 'encrypted:a-long-wisecp-password'], 'creation_info' => [
            'package' => 'none',
            'location' => 'location-uuid',
            'os_template' => 'os-uuid',
            'cpu_cores' => '4',
            'memory_gib' => '8',
            'disk_gib' => '80',
            'ipv6_count' => '',
            'traffic_gb' => '1000',
        ]])->create();

        $body = $this->api->body('POST /servers');
        $this->assertNull($body['package']);
        $this->assertSame('bridged', $body['network_model']);
        $this->assertSame(['cpu_cores' => 4, 'memory_mib' => 8192, 'disk_mib' => 81920, 'traffic_bytes' => 1000000000000], $body['resources']);
    }

    public function test_extra_add_ons_without_a_package_are_refused_readably(): void
    {
        $this->serviceHas(null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Add-ons with extra_ values add to a package');

        $this->module(['creation_info' => ['package' => 'none']], [], ['extra_cpu_cores' => 1])->create();
    }

    public function test_without_a_usable_password_proxmotions_password_is_kept_on_the_service(): void
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 200, ['data' => ['root_password' => 'made-by-proxmotion']]);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $module = $this->module(['login' => ['password' => 'encrypted:short']]);
        $result = $module->create();

        $this->assertArrayNotHasKey('root_password', $this->api->body('POST /servers'));
        $this->assertSame(['username' => 'root', 'password' => 'encrypted:made-by-proxmotion'], $result['login']);
        $this->assertStringNotContainsString('made-by-proxmotion', json_encode($module->logs) ?: '', 'The password never reaches the module log.');
    }

    public function test_an_invalid_hostname_falls_back_to_the_service_id(): void
    {
        $this->serviceHas(null);
        $this->api->on('GET /locations', 200, ['data' => [['id' => 'location-uuid', 'name' => 'Istanbul', 'generates_hostnames' => false]]]);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $this->module(['hostname' => 'not a hostname!', 'login' => ['password' => 'encrypted:a-long-wisecp-password']])->create();

        $this->assertSame('vps42', $this->api->body('POST /servers')['hostname']);
    }

    public function test_create_again_for_a_service_with_a_server_orders_nothing(): void
    {
        $this->serviceHas($this->server(['status' => 'provisioning']));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $result = $this->module()->create();

        $this->assertNotContains('POST /servers', $this->api->sent());
        $this->assertSame(['server_id' => self::SERVER_ID], $result['config']);
    }

    public function test_the_order_is_sent_again_after_a_lost_connection_with_the_same_key(): void
    {
        $this->serviceHas(null);
        $this->api->failOn('POST /servers')->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);

        $this->module(['login' => ['password' => 'encrypted:a-long-wisecp-password']])->create();

        $posts = array_values(array_filter($this->api->requests, static fn (array $request): bool => $request['method'] . ' ' . $request['path'] === 'POST /servers'));
        $this->assertCount(2, $posts);
        $this->assertSame($posts[0]['headers']['Idempotency-Key'], $posts[1]['headers']['Idempotency-Key']);
    }

    public function test_suspend_passes_the_reason_and_skips_a_suspended_server(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/suspend', 202, $this->task('suspend'));

        $module = $this->module();
        $module->service['suspended_reason'] = 'Overdue invoice';
        $this->assertTrue($module->suspend());
        $this->assertSame(['reason' => 'Overdue invoice'], $this->api->body('POST /servers/' . self::SERVER_ID . '/suspend'));

        $this->setUp();
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->assertTrue($this->module()->suspend());
        $this->assertSame(['GET /servers?external_id=42'], $this->api->sent());
    }

    public function test_unsuspend_and_cancel(): void
    {
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->api->on('POST /servers/' . self::SERVER_ID . '/unsuspend', 202, $this->task('unsuspend'));
        $this->api->on('DELETE /servers/' . self::SERVER_ID, 202, $this->task('terminate'));

        $this->assertTrue($this->module()->unsuspend());
        $this->assertTrue($this->module()->cancel());

        $this->assertContains('POST /servers/' . self::SERVER_ID . '/unsuspend', $this->api->sent());
        $this->assertContains('DELETE /servers/' . self::SERVER_ID, $this->api->sent());
    }

    public function test_cancel_without_a_server_is_fine(): void
    {
        $this->serviceHas(null);

        $this->assertTrue($this->module()->cancel());
        $this->assertSame(['GET /servers?external_id=42'], $this->api->sent());
    }

    public function test_suspend_without_a_server_says_so(): void
    {
        $this->serviceHas(null);

        $this->expectExceptionMessage('No server was found in Proxmotion for this service.');

        $this->module()->suspend();
    }

    public function test_an_upgrade_sends_the_new_products_package_and_resources(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/package', 202, $this->task('change_resources'));
        $this->api->on('GET /servers/' . self::SERVER_ID . '/app-entitlements', 200, ['data' => []]);

        $this->assertTrue($this->module()->upgrade(['module_data' => [
            'package' => 'bigger-package',
            'disk_gib' => '100',
            'reboot_after_upgrade' => '1',
        ]]));

        $request = $this->api->last('POST /servers/' . self::SERVER_ID . '/package');
        $this->assertSame(['package' => 'bigger-package', 'resources' => ['disk_mib' => 102400], 'reboot' => true], $request['body']);
        $this->assertMatchesRegularExpression('/^wisecp-42-change-/', $request['headers']['Idempotency-Key']);
    }

    public function test_an_app_order_answer_orders_the_app_with_its_settings(): void
    {
        $this->serviceHas(null);
        $this->api->on('GET /apps?mode=order&os_template=os-uuid', 200, ['data' => [
            ['id' => 'openvpn', 'name' => 'OpenVPN', 'inputs' => [['key' => 'port'], ['key' => 'protocol']]],
        ]]);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 410, ['message' => 'Gone']);

        $this->module([], ['app' => 'OpenVPN', 'app_inputs' => "port = 443\nprotocol=\ndomain=other.example.com\nnot a setting"])->create();

        $body = $this->api->last('POST /servers')['body'];
        $this->assertIsArray($body);
        $this->assertSame('openvpn', $body['app']);
        $this->assertSame(['port' => '443'], $body['app_inputs'], 'Empty values and keys of other apps are left out.');
        $this->assertNotContains('GET /servers/' . self::SERVER_ID . '/app-entitlements', $this->api->sent(), 'No paid add-ons: nothing to grant.');
    }

    public function test_paid_app_add_ons_are_granted_with_the_order(): void
    {
        $this->serviceHas(null);
        $this->api->on('POST /servers', 202, $this->task('create'));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 410, ['message' => 'Gone']);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/app-entitlements', 200, ['data' => []]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/app-entitlements', 201, ['data' => ['active' => true]]);

        $this->module([], [], ['paid_app' => 'cPanel, plesk cpanel'])->create();

        $grants = array_values(array_filter($this->api->requests, static fn (array $request): bool => $request['method'] === 'POST' && str_ends_with($request['path'], '/app-entitlements')));
        $this->assertSame([['app' => 'cpanel', 'reference' => 'wisecp-addon'], ['app' => 'plesk', 'reference' => 'wisecp-addon']], array_column($grants, 'body'));
    }

    public function test_an_upgrade_revokes_paid_apps_whose_add_on_is_gone(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/package', 202, $this->task('change_resources'));
        $this->api->on('GET /servers/' . self::SERVER_ID . '/app-entitlements', 200, ['data' => [
            ['app' => ['id' => 'cpanel'], 'source' => 'api', 'reference' => 'wisecp-addon', 'active' => true],
            ['app' => ['id' => 'plesk'], 'source' => 'order', 'reference' => null, 'active' => true],
            ['app' => ['id' => 'aapanel'], 'source' => 'api', 'reference' => 'wisecp-addon', 'active' => false],
        ]]);
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/app-entitlements/cpanel', 200, ['data' => ['active' => false]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/app-entitlements', 201, ['data' => ['active' => true]]);

        $this->module([], [], ['paid_app' => 'plesk directadmin'])->upgrade(['module_data' => ['package' => self::PACKAGE_ID]]);

        $this->assertContains('DELETE /servers/' . self::SERVER_ID . '/app-entitlements/cpanel', $this->api->sent());
        $this->assertNotContains('DELETE /servers/' . self::SERVER_ID . '/app-entitlements/plesk', $this->api->sent(), 'Rights from the order stay.');
        $this->assertSame(['app' => 'directadmin', 'reference' => 'wisecp-addon'], $this->api->last('POST /servers/' . self::SERVER_ID . '/app-entitlements')['body']);
    }

    public function test_change_password_sets_the_password_and_waits_for_it(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));
        $this->api->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'running'))
            ->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'succeeded'));

        $module = $this->module();
        $this->assertTrue($module->change_password('a-brand-new-password'));

        $this->assertSame(['root_password' => 'a-brand-new-password'], $this->api->body('POST /servers/' . self::SERVER_ID . '/password'));
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($module->logs) ?: '');
    }

    public function test_change_password_fails_when_the_task_fails(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));
        $this->api->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'failed'));

        $this->expectExceptionMessage('Proxmotion could not set the root password.');

        $this->module()->change_password('a-brand-new-password');
    }

    public function test_a_password_reset_still_running_after_the_wait_counts_as_set(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));
        $this->api->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'running'));

        $this->assertTrue($this->module()->change_password('a-brand-new-password'));
    }

    public function test_a_short_password_is_refused_before_asking_proxmotion(): void
    {
        try {
            $this->module()->change_password('short');
            $this->fail('A short password was accepted.');
        } catch (Exception $e) {
            $this->assertSame('The root password must have 12 to 128 characters.', $e->getMessage());
        }

        $this->assertSame([], $this->api->sent());
    }

    public function test_the_connection_test_reads_packages_and_servers(): void
    {
        $this->api->on('GET /packages', 200, ['data' => []]);
        $this->api->on('GET /servers', 200, ['data' => []]);

        $this->assertTrue($this->module()->test_connect());
    }

    public function test_a_refused_token_is_reported_with_proxmotions_message(): void
    {
        $this->api->on('GET /packages', 401, ['message' => 'Unauthenticated.']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unauthenticated.');

        $this->module()->test_connect();
    }

    public function test_the_product_settings_list_proxmotions_choices(): void
    {
        $this->api->on('GET /packages', 200, ['data' => [['id' => self::PACKAGE_ID, 'name' => 'VPS S']]]);
        $this->api->on('GET /locations', 200, ['data' => [['id' => 'location-uuid', 'name' => 'Istanbul']]]);
        $this->api->on('GET /os-templates', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu']]]);

        $fields = $this->module()->product_configuration(['package' => self::PACKAGE_ID, 'reboot_after_upgrade' => '1']);

        $this->assertSame(['none' => 'No package (resources below)', self::PACKAGE_ID => 'VPS S'], $fields['package']['options']);
        $this->assertSame(self::PACKAGE_ID, $fields['package']['value']);
        $this->assertSame(['location-uuid' => 'Istanbul'], $fields['location']['options']);
        $this->assertSame(['os-uuid' => 'Ubuntu 24.04'], $fields['os_template']['options']);
        $this->assertTrue($fields['reboot_after_upgrade']['checked']);
        $this->assertSame('text', $fields['memory_gib']['type']);
    }

    public function test_without_a_connection_the_product_settings_take_ids(): void
    {
        $this->api->failOn('GET /packages');

        $fields = $this->module()->product_configuration(['location' => 'location-uuid']);

        $this->assertSame('text', $fields['location']['type']);
        $this->assertSame('location-uuid', $fields['location']['value']);
        $this->assertStringContainsString('Could not connect to Proxmotion', (string) $fields['location']['description']);
    }

    public function test_the_api_token_never_reaches_the_module_log(): void
    {
        $this->api->on('GET /packages', 200, ['data' => []]);
        $this->api->on('GET /servers', 200, ['data' => [['echo' => self::TOKEN]]]);

        $module = $this->module();
        $module->test_connect();

        $this->assertCount(2, $module->logs);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($module->logs) ?: '');
    }

    public function test_the_panel_url_comes_from_the_wisecp_server(): void
    {
        $this->assertSame('https://panel.example.com', Proxmotion::baseUrl(['ip' => 'panel.example.com', 'secure' => 1, 'port' => 443]));
        $this->assertSame('https://panel.example.com:8443', Proxmotion::baseUrl(['ip' => 'https://panel.example.com/', 'secure' => 1, 'port' => 8443]));
        $this->assertSame('http://192.0.2.10', Proxmotion::baseUrl(['ip' => '192.0.2.10', 'secure' => 0, 'port' => 80]));
        $this->assertSame('https://panel.example.com', Proxmotion::baseUrl(['ip' => '0.0.0.0', 'name' => 'panel.example.com', 'secure' => 1]));
    }
}
