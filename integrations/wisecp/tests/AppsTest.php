<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

/**
 * The Apps tool (App Marketplace, ADR 0015): installed and installable apps,
 * installing with inputs, retrying, downloading file outputs, the page.
 */
final class AppsTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    private const PROFILE = "[Interface]\nPrivateKey = secret-key\n";

    /**
     * @return array<string, mixed>
     */
    private static function wireguard(): array
    {
        return [
            'id' => 'wireguard',
            'name' => 'WireGuard',
            'description' => ['en' => 'A VPN server.', 'tr' => 'Bir VPN sunucusu.'],
            'inputs' => [
                ['key' => 'port', 'type' => 'port', 'label' => ['en' => 'UDP port', 'tr' => 'UDP portu'], 'required' => false, 'default' => 51820, 'options' => null, 'min' => 1, 'max' => 65535, 'max_length' => null],
                ['key' => 'ipv6', 'type' => 'boolean', 'label' => null, 'required' => false, 'default' => true, 'options' => null, 'min' => null, 'max' => null, 'max_length' => null],
                ['key' => 'protocol', 'type' => 'enum', 'label' => ['en' => 'Protocol'], 'required' => true, 'default' => 'udp', 'options' => ['udp', 'tcp'], 'min' => null, 'max' => null, 'max_length' => null],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function installation(array $overrides = []): array
    {
        return array_replace([
            'id' => 'installation-uuid',
            'app' => ['id' => 'wireguard', 'name' => 'WireGuard', 'version' => '1.0.0'],
            'status' => 'installed',
            'progress' => 100,
            'step' => null,
            'error' => null,
            'inputs' => ['port' => 51820],
            'outputs' => [
                ['key' => 'client_config', 'type' => 'file', 'label' => ['en' => 'Client configuration', 'tr' => 'İstemci yapılandırması'], 'secret' => true, 'value' => null, 'filename' => 'client.conf', 'content_base64' => base64_encode(self::PROFILE)],
                ['key' => 'endpoint', 'type' => 'text', 'label' => ['en' => 'Server endpoint'], 'secret' => false, 'value' => '203.0.113.10:51820', 'filename' => null, 'content_base64' => null],
            ],
        ], $overrides);
    }

    /**
     * What the reinstall form of the page asks for (ADR 0016 §5).
     *
     * @param list<array<string, mixed>> $apps the order catalog for the server
     */
    private function reinstallRoutes(array $apps = []): void
    {
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu']]]);
        $this->api->on('GET /apps?mode=order&server=' . self::SERVER_ID, 200, ['data' => $apps]);
    }

    public function test_the_reinstall_form_offers_apps_the_server_may_install_with_their_defaults(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [self::wireguard() + ['billing' => 'free', 'entitled' => true]]]);
        $this->reinstallRoutes([
            self::wireguard() + ['billing' => 'free', 'entitled' => true],
            ['id' => 'cpanel', 'name' => 'cPanel', 'inputs' => [], 'billing' => 'paid', 'entitled' => false],
            ['id' => 'wordpress', 'name' => 'WordPress', 'inputs' => [['key' => 'domain', 'required' => true, 'default' => null]], 'billing' => 'free', 'entitled' => true],
        ]);

        $data = $this->module()->tool_data('apps', 'index');

        $this->assertSame(['templates' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04']], 'apps' => [['id' => 'wireguard', 'name' => 'WireGuard']]], $data['reinstall']);
        $this->assertFalse($data['apps'][0]['buy']);
    }

    public function test_a_reinstall_with_an_app_needs_the_confirmation(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04']]]);
        $this->api->on('POST ' . self::SERVER . '/reinstall', 202, $this->task('reinstall'));

        try {
            $this->module()->tool_action('apps', 'reinstall', ['os_template' => 'os-uuid', 'app' => 'docker']);
            $this->fail('A reinstall without confirmation was started.');
        } catch (\Exception $e) {
            $this->assertSame('Please confirm that all data may be deleted.', $e->getMessage());
        }

        $this->assertSame('successful', $this->module()->tool_action('apps', 'reinstall', ['os_template' => 'os-uuid', 'app' => 'docker', 'confirm' => '1'])['status']);
        $this->assertSame(['os_template' => 'os-uuid', 'app' => 'docker'], $this->api->last('POST ' . self::SERVER . '/reinstall')['body']);
    }

    public function test_the_page_offers_paid_apps_to_buy_and_the_reinstall_form(): void
    {
        $html = $this->page([
            'installations' => [],
            'apps' => [
                ['id' => 'cpanel', 'name' => 'cPanel', 'description' => '', 'inputs' => [], 'buy' => true, 'licence_required' => true],
                ['id' => 'plesk', 'name' => 'Plesk', 'description' => '', 'inputs' => [], 'buy' => false, 'licence_required' => true],
            ],
            'installing' => false,
            'reinstall' => ['templates' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04']], 'apps' => [['id' => 'docker', 'name' => 'Docker']]],
        ]);

        $this->assertStringNotContainsString('data-app="cpanel"', $html, 'No install form without the right.');
        $this->assertStringContainsString('buy it as an add-on of this service', $html);
        $this->assertStringContainsString('data-app="plesk"', $html);
        $this->assertStringContainsString('This app needs a licence', $html);
        $this->assertStringContainsString('onsubmit="return pmxAppReinstall(this, event)"', $html);
        $this->assertStringContainsString('<option value="docker">Docker</option>', $html);
    }

    private function turkish(TestProxmotion $module): TestProxmotion
    {
        $module->lang = include PROXMOTION_WISECP_MODULE . '/lang/tr.php';

        return $module;
    }

    public function test_the_tool_is_added_to_the_server_tools(): void
    {
        $tool = $this->module()->get_tool('apps');

        $this->assertNotNull($tool);
        $this->assertSame('Apps', $tool['label']);
        $this->assertSame('page-loader', $tool['type']);
        $this->assertSame(['list', 'install', 'retry', 'cancel', 'download', 'uninstall', 'upgrade', 'action'], $tool['capabilities']);
        $this->assertTrue($tool['supported']);
    }

    public function test_the_tool_is_hidden_unless_the_server_is_active(): void
    {
        $busy = $this->module();
        $busy->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'suspended']]]);
        $this->assertContains('apps', $busy->removedTools);

        $running = $this->module();
        $running->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'running']]]);
        $this->assertNotContains('apps', $running->removedTools);
    }

    public function test_the_page_lists_installed_and_installable_apps_in_the_customers_language(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps', 200, ['data' => [self::installation()]]);
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid/runs', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [self::wireguard()]]);

        $this->reinstallRoutes();
        $data = $this->turkish($this->module())->tool_data('apps', 'index');

        $this->assertFalse($data['installing']);
        $this->assertArrayNotHasKey('busy', $data, 'WISECP shows its busy notice for any busy key.');
        $this->assertSame('Kuruldu', $data['installations'][0]['status_label']);
        $this->assertSame(['key' => 'client_config', 'type' => 'file', 'label' => 'İstemci yapılandırması', 'value' => null, 'filename' => 'client.conf'], $data['installations'][0]['outputs'][0]);
        $this->assertSame('Server endpoint', $data['installations'][0]['outputs'][1]['label'], 'English without a Turkish text.');
        $this->assertSame('203.0.113.10:51820', $data['installations'][0]['outputs'][1]['value']);
        $this->assertSame('Bir VPN sunucusu.', $data['apps'][0]['description']);
        $this->assertSame(['key' => 'port', 'type' => 'port', 'label' => 'UDP portu', 'required' => false, 'default' => '51820', 'options' => [], 'min' => 1, 'max' => 65535], $data['apps'][0]['inputs'][0]);
        $this->assertSame(['ipv6', '1'], [$data['apps'][0]['inputs'][1]['label'], $data['apps'][0]['inputs'][1]['default']], 'The key without a label; true as 1.');
        $this->assertSame(['udp', 'tcp'], $data['apps'][0]['inputs'][2]['options']);
    }

    public function test_no_apps_are_offered_while_one_installs(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps', 200, ['data' => [self::installation(['status' => 'installing', 'progress' => 40, 'step' => 'Installing packages', 'outputs' => []])]]);
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid/runs', 200, ['data' => []]);

        $this->reinstallRoutes();
        $data = $this->module()->tool_data('apps', 'index');

        $this->assertTrue($data['installing']);
        $this->assertSame([], $data['apps']);
        $this->assertSame(40, $data['installations'][0]['progress']);
        $this->assertNotContains('GET /apps?server=' . self::SERVER_ID, $this->api->sent());
    }

    public function test_an_app_is_installed_with_the_filled_in_inputs(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/apps', 202, ['data' => self::installation(['status' => 'queued', 'outputs' => []])]);

        $result = $this->module()->tool_action('apps', 'install', [
            'app' => 'openvpn',
            'secret_inputs' => (string) json_encode(['port' => ' 443 ', 'protocol' => 'tcp', 'empty' => '', 'nested' => ['x'], 'admin_password' => 'p<a>ss"word']),
        ]);

        $this->assertSame('successful', $result['status']);
        $request = $this->api->last('POST ' . self::SERVER . '/apps');
        $this->assertSame(['app' => 'openvpn', 'inputs' => ['port' => '443', 'protocol' => 'tcp', 'admin_password' => 'p<a>ss"word']], $request['body']);
        $this->assertMatchesRegularExpression('/^wisecp-42-app-/', $request['headers']['Idempotency-Key']);
    }

    public function test_installing_needs_an_app_and_readable_inputs(): void
    {
        $this->serviceHas($this->server());

        foreach ([['app' => ''], ['app' => 'openvpn', 'secret_inputs' => 'not json']] as $data) {
            try {
                $this->module()->tool_action('apps', 'install', $data);
                $this->fail('Nothing should have been sent.');
            } catch (\Exception $e) {
                $this->assertContains($e->getMessage(), ['Please choose an app.', 'The app settings could not be read. Reload the page and try again.']);
            }
        }

        $this->assertNotContains('POST ' . self::SERVER . '/apps', $this->api->sent());
    }

    public function test_proxmotions_refusal_reaches_the_customer(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/apps', 422, ['message' => 'The app cannot be installed on this server.', 'errors' => ['app' => ['The app cannot be installed on this server.']]]);

        $this->expectExceptionMessage('The app cannot be installed on this server.');

        $this->module()->tool_action('apps', 'install', ['app' => 'docker', 'secret_inputs' => '{}']);
    }

    public function test_a_failed_installation_can_be_retried(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/apps/installation-uuid/retry', 202, ['data' => self::installation(['status' => 'queued'])]);

        $result = $this->module()->tool_action('apps', 'retry', ['installation' => 'installation-uuid']);

        $this->assertSame('successful', $result['status']);
        $this->assertMatchesRegularExpression('/^wisecp-42-app-retry-/', $this->api->last('POST ' . self::SERVER . '/apps/installation-uuid/retry')['headers']['Idempotency-Key']);
    }

    public function test_a_running_installation_or_run_can_be_cancelled(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/apps/installation-uuid/cancel', 202, ['data' => self::installation(['status' => 'failed'])]);
        $this->api->on('POST ' . self::SERVER . '/apps/installation-uuid/runs/run-uuid/cancel', 202, ['data' => self::appRun(['status' => 'failed'])]);

        $this->assertSame('successful', $this->module()->tool_action('apps', 'cancel', ['installation' => 'installation-uuid'])['status']);
        $this->assertSame('successful', $this->module()->tool_action('apps', 'cancel', ['installation' => 'installation-uuid', 'run' => 'run-uuid'])['status']);
        $this->assertMatchesRegularExpression('/^wisecp-42-app-cancel-/', $this->api->last('POST ' . self::SERVER . '/apps/installation-uuid/cancel')['headers']['Idempotency-Key']);
        $this->assertMatchesRegularExpression('/^wisecp-42-app-run-cancel-/', $this->api->last('POST ' . self::SERVER . '/apps/installation-uuid/runs/run-uuid/cancel')['headers']['Idempotency-Key']);

        $html = $this->page([
            'installations' => [[
                'id' => 'installation-uuid',
                'name' => 'WireGuard',
                'status' => 'installing',
                'status_label' => 'Installing',
                'busy' => true,
                'failed' => false,
                'can_cancel' => true,
                'outputs' => [],
                'can_uninstall' => false,
                'actions' => [],
                'runs' => [['id' => 'run-uuid', 'label' => 'Add a client', 'status' => 'running', 'status_label' => 'Running', 'busy' => true, 'failed' => false, 'can_cancel' => true, 'error' => null, 'outputs' => []]],
                'run_busy' => true,
            ]],
            'apps' => [],
            'installing' => true,
        ]);
        $this->assertStringContainsString('onclick="pmxAppCancel(this)" data-installation="installation-uuid" data-confirm=', $html);
        $this->assertStringContainsString('data-run="run-uuid"', $html);
    }

    public function test_a_file_output_is_fetched_when_downloaded_and_never_logged(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid', 200, ['data' => self::installation()]);

        $module = $this->module();
        $result = $module->tool_action('apps', 'download', ['installation' => 'installation-uuid', 'output' => 'client_config']);

        $this->assertSame(['status' => 'successful', 'file' => ['name' => 'client.conf', 'content_base64' => base64_encode(self::PROFILE)]], $result);

        $log = (string) json_encode($module->logs);
        $this->assertStringNotContainsString(base64_encode(self::PROFILE), $log);
        $this->assertStringNotContainsString('203.0.113.10:51820', $log);
        $this->assertStringContainsString('client.conf', $log, 'Only the secret parts are masked.');

        $this->expectExceptionMessage('This file is not available.');
        $this->module()->tool_action('apps', 'download', ['installation' => 'installation-uuid', 'output' => 'endpoint']);
    }

    public function test_app_inputs_are_masked_in_the_module_log(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/apps', 202, ['data' => self::installation(['status' => 'queued', 'outputs' => []])]);

        $module = $this->module();
        $module->tool_action('apps', 'install', ['app' => 'openvpn', 'secret_inputs' => '{"admin_password":"very-secret-pw"}']);

        $this->assertStringNotContainsString('very-secret-pw', (string) json_encode($module->logs));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($module->logs));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function appRun(array $overrides = []): array
    {
        return array_replace([
            'id' => 'run-uuid',
            'installation_id' => 'installation-uuid',
            'kind' => 'action',
            'action' => 'add_client',
            'status' => 'succeeded',
            'error' => null,
            'inputs' => ['name' => 'laptop'],
            'outputs' => [['key' => 'client_config', 'type' => 'file', 'label' => ['en' => 'Client configuration'], 'secret' => true, 'value' => null, 'filename' => 'client.conf', 'content_base64' => base64_encode("[Interface]\nPrivateKey = laptop-key\n")]],
        ], $overrides);
    }

    public function test_the_page_data_has_actions_uninstall_and_recent_runs(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps', 200, ['data' => [self::installation([
            'can_uninstall' => true,
            'actions' => [['key' => 'add_client', 'label' => ['en' => 'Add a client', 'tr' => 'İstemci ekle'], 'inputs' => [['key' => 'name', 'type' => 'slug', 'label' => null, 'required' => true, 'default' => null]], 'outputs' => []]],
        ])]]);
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid/runs', 200, ['data' => [self::appRun(), self::appRun(['id' => 'other-uuid', 'kind' => 'uninstall', 'action' => null, 'status' => 'running', 'outputs' => []])]]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);

        $this->reinstallRoutes();
        $installation = $this->turkish($this->module())->tool_data('apps', 'index')['installations'][0];

        $this->assertTrue($installation['can_uninstall']);
        $this->assertSame('İstemci ekle', $installation['actions'][0]['label']);
        $this->assertSame(['İstemci ekle', 'Tamamlandı', 'client.conf'], [$installation['runs'][0]['label'], $installation['runs'][0]['status_label'], $installation['runs'][0]['outputs'][0]['filename']]);
        $this->assertSame('Kaldır', $installation['runs'][1]['label']);
        $this->assertTrue($installation['run_busy']);
    }

    public function test_an_app_is_uninstalled_and_an_action_runs_with_its_inputs(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('DELETE ' . self::SERVER . '/apps/installation-uuid', 202, ['data' => self::appRun(['kind' => 'uninstall', 'status' => 'running'])]);
        $this->api->on('POST ' . self::SERVER . '/apps/installation-uuid/actions/add_client', 202, ['data' => self::appRun(['status' => 'running', 'outputs' => []])]);

        $module = $this->module();
        $this->assertSame('successful', $module->tool_action('apps', 'uninstall', ['installation' => 'installation-uuid'])['status']);
        $this->assertMatchesRegularExpression('/^wisecp-42-app-uninstall-/', $this->api->last('DELETE ' . self::SERVER . '/apps/installation-uuid')['headers']['Idempotency-Key']);

        $result = $module->tool_action('apps', 'action', ['installation' => 'installation-uuid', 'app_action' => 'add_client', 'secret_inputs' => '{"name":" laptop ","empty":""}']);
        $this->assertSame('successful', $result['status']);
        $this->assertSame(['inputs' => ['name' => 'laptop']], $this->api->body('POST ' . self::SERVER . '/apps/installation-uuid/actions/add_client'));
        $this->assertStringNotContainsString('laptop', (string) json_encode($module->logs), 'Action inputs are masked in the module log.');

        $this->expectExceptionMessage('Please choose an action.');
        $module->tool_action('apps', 'action', ['installation' => 'installation-uuid', 'app_action' => '../x']);
    }

    public function test_an_offered_upgrade_is_shown_and_sent_with_the_new_inputs(): void
    {
        $upgrade = ['version' => '1.4.0', 'inputs' => [['key' => 'dns', 'type' => 'domain', 'label' => ['en' => 'DNS server', 'tr' => 'DNS sunucusu'], 'required' => true, 'default' => null]]];
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps', 200, ['data' => [
            self::installation(['upgrade' => $upgrade]),
            self::installation(['id' => 'failed-uuid', 'status' => 'upgrade_failed', 'error' => 'apt failed', 'upgrade' => null]),
        ]]);
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid/runs', 200, ['data' => [self::appRun(['kind' => 'upgrade', 'action' => null, 'target_version' => '1.4.0', 'outputs' => []])]]);
        $this->api->on('GET ' . self::SERVER . '/apps/failed-uuid/runs', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('POST ' . self::SERVER . '/apps/installation-uuid/upgrade', 202, ['data' => self::appRun(['kind' => 'upgrade', 'action' => null, 'status' => 'running', 'outputs' => []])]);

        $this->reinstallRoutes();
        $installations = $this->turkish($this->module())->tool_data('apps', 'index')['installations'];

        $this->assertSame(['1.4.0', '1.4.0 sürümüne yükselt', 'DNS sunucusu'], [$installations[0]['upgrade']['version'], $installations[0]['upgrade']['label'], $installations[0]['upgrade']['inputs'][0]['label']]);
        $this->assertSame('1.4.0 sürümüne yükselt', $installations[0]['runs'][0]['label']);
        $this->assertNull($installations[1]['upgrade']);
        $this->assertSame(['Yükseltme başarısız', true, false], [$installations[1]['status_label'], $installations[1]['danger'], $installations[1]['failed']]);

        $module = $this->module();
        $result = $module->tool_action('apps', 'upgrade', ['installation' => 'installation-uuid', 'secret_inputs' => '{"dns":" dns.example.com ","empty":""}']);
        $this->assertSame('successful', $result['status']);
        $this->assertSame(['inputs' => ['dns' => 'dns.example.com']], $this->api->body('POST ' . self::SERVER . '/apps/installation-uuid/upgrade'));
        $this->assertMatchesRegularExpression('/^wisecp-42-app-upgrade-/', $this->api->last('POST ' . self::SERVER . '/apps/installation-uuid/upgrade')['headers']['Idempotency-Key']);
        $this->assertStringNotContainsString('dns.example.com', (string) json_encode($module->logs), 'Upgrade inputs are masked in the module log.');

        $html = $this->page([
            'installations' => [[
                'id' => 'installation-uuid',
                'name' => 'WireGuard',
                'status' => 'installed',
                'status_label' => 'Installed',
                'busy' => false,
                'failed' => false,
                'outputs' => [],
                'can_uninstall' => false,
                'actions' => [],
                'upgrade' => ['version' => '1.4.0', 'label' => 'Upgrade to 1.4.0', 'inputs' => [['key' => 'dns', 'type' => 'domain', 'label' => 'DNS server', 'required' => true, 'default' => '']]],
                'runs' => [],
                'run_busy' => false,
            ]],
            'apps' => [],
            'installing' => false,
        ]);
        $this->assertStringContainsString('onsubmit="return pmxAppUpgrade(this, event)"', $html);
        $this->assertStringContainsString('Upgrade to 1.4.0', $html);
        $this->assertStringContainsString('data-input="dns"', $html);
    }

    public function test_a_file_output_of_a_run_is_downloaded_and_never_logged(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/apps/installation-uuid/runs/run-uuid', 200, ['data' => self::appRun()]);

        $module = $this->module();
        $result = $module->tool_action('apps', 'download', ['installation' => 'installation-uuid', 'run' => 'run-uuid', 'output' => 'client_config']);

        $this->assertSame(['name' => 'client.conf', 'content_base64' => base64_encode("[Interface]\nPrivateKey = laptop-key\n")], $result['file']);
        $this->assertStringNotContainsString(base64_encode("[Interface]\nPrivateKey = laptop-key\n"), (string) json_encode($module->logs));
    }

    public function test_the_page_offers_actions_uninstall_and_run_downloads(): void
    {
        $html = $this->page([
            'installations' => [[
                'id' => 'installation-uuid',
                'name' => 'WireGuard',
                'status' => 'installed',
                'status_label' => 'Installed',
                'busy' => false,
                'failed' => false,
                'outputs' => [],
                'can_uninstall' => true,
                'actions' => [['key' => 'add_client', 'label' => 'Add a client', 'inputs' => [['key' => 'name', 'type' => 'slug', 'label' => 'Client name', 'required' => true, 'default' => '']]]],
                'runs' => [['id' => 'run-uuid', 'label' => 'Add a client', 'status' => 'succeeded', 'status_label' => 'Done', 'busy' => false, 'failed' => false, 'error' => null, 'outputs' => [['key' => 'client_config', 'type' => 'file', 'label' => 'Client configuration', 'value' => null, 'filename' => 'client.conf']]]],
                'run_busy' => false,
            ]],
            'apps' => [],
            'installing' => false,
        ]);

        $this->assertStringContainsString('data-action="add_client"', $html);
        $this->assertStringContainsString('data-input="name"', $html);
        $this->assertStringContainsString('onclick="pmxAppUninstall(this)"', $html);
        $this->assertStringContainsString('data-run="run-uuid" data-output="client_config"', $html);
        $this->assertStringNotContainsString('}, 30000);', $html, 'Nothing runs: no reload.');
    }

    public function test_inputs_bypass_wisecps_tag_stripping_under_a_masked_name(): void
    {
        $filters = (new \ReflectionMethod(TestProxmotion::class, 'get_tool_action_data_filters'))->invoke($this->module());

        $this->assertIsArray($filters);
        $this->assertArrayHasKey('secret_inputs', $filters['apps']);
        $this->assertNull($filters['apps']['secret_inputs']);
        $this->assertSame(1, preg_match('/pass(word)?|secret|token/i', 'secret_inputs'), 'WISECP masks this field in the service history.');
    }

    public function test_the_page_shows_installations_and_forms_escaped(): void
    {
        $html = $this->page([
            'installations' => [[
                'id' => 'installation-uuid',
                'name' => 'WireGuard',
                'status' => 'failed',
                'status_label' => 'Failed',
                'busy' => false,
                'failed' => true,
                'progress' => 60,
                'step' => null,
                'error' => 'apt-get failed <script>',
                'outputs' => [
                    ['key' => 'client_config', 'type' => 'file', 'label' => 'Client configuration', 'value' => null, 'filename' => 'client.conf'],
                    ['key' => 'panel', 'type' => 'url', 'label' => 'Panel', 'value' => 'javascript:alert(1)', 'filename' => null],
                ],
            ]],
            'apps' => [[
                'id' => 'wireguard',
                'name' => 'WireGuard',
                'description' => 'A VPN server.',
                'inputs' => [['key' => 'port', 'type' => 'port', 'label' => 'UDP port', 'required' => true, 'default' => '51820', 'options' => [], 'min' => 1, 'max' => 65535]],
            ]],
            'installing' => false,
        ]);

        $this->assertStringContainsString('apt-get failed &lt;script&gt;', $html);
        $this->assertStringContainsString('data-output="client_config"', $html);
        $this->assertStringContainsString('onclick="pmxAppRetry(this)"', $html);
        $this->assertStringNotContainsString('href="javascript:', $html, 'Only http(s) outputs become links.');
        $this->assertStringContainsString('data-app="wireguard"', $html);
        $this->assertMatchesRegularExpression('/type="number" value="51820"\s+min="1" max="65535" required/', $html);
    }

    public function test_the_page_says_when_an_app_is_installing(): void
    {
        $html = $this->page(['installations' => [], 'apps' => [], 'installing' => true]);

        $this->assertStringContainsString('An app is being installed.', $html);
        $this->assertStringContainsString('}, 30000);', $html, 'The page reloads itself.');
    }

    /**
     * pages/tools/apps.php as WISECP renders it (ServerModule::render_tool_page).
     *
     * @param array<string, mixed> $data
     */
    private function page(array $data): string
    {
        $render = static function (array $vars): string {
            extract($vars);
            ob_start();
            include PROXMOTION_WISECP_MODULE . '/pages/tools/apps.php';

            return (string) ob_get_clean();
        };

        return $render([
            'data' => $data,
            'error' => null,
            'tool_label' => 'Apps',
            'lang' => include PROXMOTION_WISECP_MODULE . '/lang/en.php',
        ]);
    }
}
