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
        $this->assertSame(['list', 'install', 'retry', 'download'], $tool['capabilities']);
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
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [self::wireguard()]]);

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
