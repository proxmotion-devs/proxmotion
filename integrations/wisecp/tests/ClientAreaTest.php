<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

/**
 * The service page and WISECP's server tools (power, console, reinstall,
 * hostname, reverse DNS), against a faked Proxmotion API.
 */
final class ClientAreaTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    public function test_the_service_page_shows_the_server(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server()]);

        $data = $this->module()->dashboard_data();

        $this->assertSame('web1.example.com', $data['server_info']['hostname']['value']);
        $this->assertSame('203.0.113.10', $data['server_info']['ip_address']['value']);
        $this->assertSame('Ubuntu 24.04', $data['server_info']['os']['value']);
        $this->assertSame('Istanbul', $data['server_info']['location']['value']);
        $this->assertSame('running', $data['server_info']['status']['raw']);
        $this->assertSame('success', $data['server_info']['status']['badge_color']);
        $this->assertSame('2 vCPU', $data['resource_allocation']['cpu_cores']['value']);
        $this->assertSame('4 GB', $data['resource_allocation']['ram']['value']);
        $this->assertSame('40 GB', $data['resource_allocation']['disk_space']['value']);
        $this->assertSame('1', $data['resource_allocation']['ipv4']['value']);
        $this->assertSame('1', $data['resource_allocation']['ipv6']['value']);
    }

    public function test_the_service_page_shows_the_months_traffic_against_the_quota(): void
    {
        $gb = 1000000000;
        $server = $this->server([
            'resources' => ['cpu_cores' => 2, 'memory_mib' => 4096, 'disk_mib' => 40960, 'port_speed_mbps' => 1000, 'traffic_bytes' => 100 * $gb],
            'traffic' => ['period_start' => '2026-10-01T00:00:00+03:00', 'period_end' => '2026-11-01T00:00:00+03:00', 'in_bytes' => 2 * $gb, 'out_bytes' => (int) (10.5 * $gb), 'counted_bytes' => (int) (12.5 * $gb)],
        ]);
        $this->serviceHas($server);
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $server]);

        $data = $this->module()->dashboard_data();

        $this->assertSame(['used' => 12.5, 'total' => 100.0, 'unit' => 'GB'], $data['resource_allocation']['bandwidth']);
        $this->assertSame($data['resource_allocation']['bandwidth'], $data['gauges']['bandwidth']);
    }

    public function test_traffic_without_a_quota_is_unlimited_and_unknown_from_an_older_panel(): void
    {
        $server = $this->server(['traffic' => ['period_start' => '2026-10-01T00:00:00+03:00', 'period_end' => '2026-11-01T00:00:00+03:00', 'in_bytes' => 0, 'out_bytes' => 4000000, 'counted_bytes' => 4000000]]);
        $this->serviceHas($server);
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $server]);

        $this->assertSame(['used' => 0.0, 'total' => 'unlimited', 'unit' => 'GB'], $this->module()->dashboard_data()['resource_allocation']['bandwidth']);

        $this->api = TestProxmotion::$api = new FakeTransport;
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server()]);

        $this->assertSame(['used' => null, 'total' => null, 'unit' => 'GB'], $this->module()->dashboard_data()['gauges']['bandwidth']);
    }

    public function test_a_server_being_installed_shows_its_status(): void
    {
        $this->serviceHas($this->server(['status' => 'provisioning']));
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server(['status' => 'provisioning', 'power_state' => null])]);

        $data = $this->module()->dashboard_data();

        $this->assertSame('provisioning', $data['server_info']['status']['raw']);
        $this->assertSame('warning', $data['server_info']['status']['badge_color']);
    }

    public function test_a_new_root_password_is_kept_on_the_service(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server(['root_password_available' => true])]);
        $this->api->on('GET ' . self::SERVER . '/password', 200, ['data' => ['root_password' => 'after-reinstall-pw']]);

        $module = $this->module();
        $module->dashboard_data();

        $this->assertSame('after-reinstall-pw', $module->get_login_password());
    }

    public function test_only_the_actions_that_fit_the_state_are_offered(): void
    {
        $running = $this->module();
        $running->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'running']]]);
        $this->assertSame(['start-server'], $running->removedTools);

        $stopped = $this->module();
        $stopped->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'stopped']]]);
        $this->assertSame(['stop-server', 'restart-server', 'poweroff-server'], $stopped->removedTools);

        $busy = $this->module();
        $busy->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'reinstalling']]]);
        $this->assertContains('reinstall-os', $busy->removedTools);
        $this->assertContains('vnc-console', $busy->removedTools);
    }

    public function test_power_tools_send_the_matching_action(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/power', 202, $this->task('power'));

        foreach (['start-server' => 'start', 'stop-server' => 'shutdown', 'restart-server' => 'reboot', 'poweroff-server' => 'stop'] as $tool => $action) {
            $result = $this->module()->tool_action($tool, 'index');

            $this->assertSame('successful', $result['status']);
            $this->assertSame(['action' => $action], $this->api->body('POST ' . self::SERVER . '/power'));
        }
    }

    public function test_the_console_opens_proxmotions_signed_link(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/console', 200, ['data' => ['url' => 'https://panel.example.com/console/abc?signature=x', 'expires_at' => '2026-10-17T12:00:00Z']]);

        $result = $this->module()->tool_action('vnc-console', 'create');

        $this->assertSame(['status' => 'successful', 'redirect' => 'https://panel.example.com/console/abc?signature=x'], $result);
    }

    public function test_reinstall_lists_and_accepts_only_proxmotions_templates(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu'], ['id' => 'debian-uuid', 'name' => 'Debian 13', 'family' => 'debian']]]);
        $this->api->on('POST ' . self::SERVER . '/reinstall', 202, $this->task('reinstall'));

        $data = $this->module()->tool_data('reinstall-os', 'index');
        $this->assertSame(['slug' => 'debian-uuid', 'name' => 'Debian 13', 'category' => 'debian'], $data['templates'][1]);
        $this->assertSame('Ubuntu 24.04', $data['current_os']);
        $this->assertFalse($data['password_supported']);

        $this->module()->tool_action('reinstall-os', 'create', ['os_template' => 'debian-uuid']);
        $this->assertSame(['os_template' => 'debian-uuid', 'ssh_keys' => []], $this->api->body('POST ' . self::SERVER . '/reinstall'));

        try {
            $this->module()->tool_action('reinstall-os', 'create', ['os_template' => 'someone-elses']);
            $this->fail('An unknown template was accepted.');
        } catch (\Exception $e) {
            $this->assertSame('Choose one of the listed operating systems.', $e->getMessage());
        }
    }

    public function test_reverse_dns_lists_the_addresses_and_sets_a_ptr(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server()]);
        $this->api->on('POST ' . self::SERVER . '/rdns', 200, ['data' => ['address' => '203.0.113.10', 'ptr' => 'mail.example.com']]);

        $this->assertSame([
            ['ip' => '203.0.113.10', 'hostname' => 'web1.example.com', 'version' => 'ipv4'],
            ['ip' => '2001:db8::10', 'hostname' => '', 'version' => 'ipv6'],
        ], $this->module()->tool_data('reverse-dns', 'index')['items']);

        $this->module()->tool_action('reverse-dns', 'edit', ['ip' => '203.0.113.10', 'hostname' => 'mail.example.com']);
        $this->assertSame(['address' => '203.0.113.10', 'hostname' => 'mail.example.com'], $this->api->body('POST ' . self::SERVER . '/rdns'));
    }

    public function test_the_hostname_is_changed_in_proxmotion(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER, 200, ['data' => $this->server()]);
        $this->api->on('POST ' . self::SERVER . '/hostname', 202, $this->task('change_hostname'));

        $this->assertSame(['hostname' => 'web1.example.com'], $this->module()->tool_data('hostname-change', 'index'));

        $this->module()->tool_action('hostname-change', 'edit', ['hostname' => 'Web2.Example.com']);
        $request = $this->api->last('POST ' . self::SERVER . '/hostname');
        $this->assertSame(['hostname' => 'web2.example.com', 'reboot' => false], $request['body']);
        $this->assertMatchesRegularExpression('/^wisecp-42-hostname-/', $request['headers']['Idempotency-Key']);
    }

    public function test_tools_wait_while_proxmotion_works_on_the_server(): void
    {
        $this->serviceHas($this->server(['status' => 'reinstalling']));

        $this->expectExceptionMessage('Proxmotion is still working on this server.');

        $this->module()->tool_action('start-server', 'index');
    }

    public function test_a_suspended_server_has_no_tools(): void
    {
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->expectExceptionMessage('The server is not active.');

        $this->module()->tool_data('hostname-change', 'index');
    }
}
