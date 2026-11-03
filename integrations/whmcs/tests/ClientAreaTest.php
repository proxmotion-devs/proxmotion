<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;
use Proxmotion\Whmcs\Lang;

/**
 * The client area overview, the customer's actions and the admin service tab.
 */
class ClientAreaTest extends ModuleTestCase
{
    private function showsServer(array $overrides = []): void
    {
        $this->serviceHas($this->server($overrides));
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server($overrides)]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu', 'version' => '24.04']]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [self::wireguard()]]);
        $this->api->on('GET /apps?mode=order&server=' . self::SERVER_ID, 200, ['data' => [self::wireguard()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wireguard(): array
    {
        return [
            'id' => 'wireguard',
            'name' => 'WireGuard',
            'description' => ['en' => 'A VPN server.', 'tr' => 'Bir VPN sunucusu.'],
            'inputs' => [['key' => 'port', 'type' => 'port', 'label' => ['en' => 'UDP port', 'tr' => 'UDP portu'], 'required' => false, 'default' => 51820, 'options' => null, 'min' => 1, 'max' => 65535, 'max_length' => null]],
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
            'error' => null,
            'outputs' => [
                ['key' => 'client_config', 'type' => 'file', 'label' => ['en' => 'Client configuration', 'tr' => 'İstemci yapılandırması'], 'secret' => true, 'value' => null, 'filename' => 'client.conf', 'content_base64' => base64_encode("[Interface]\nPrivateKey = secret\n")],
                ['key' => 'endpoint', 'type' => 'text', 'label' => ['en' => 'Server endpoint'], 'secret' => false, 'value' => '203.0.113.10:51820', 'filename' => null, 'content_base64' => null],
            ],
            'can_uninstall' => true,
            'actions' => [
                ['key' => 'add_client', 'label' => ['en' => 'Add a client', 'tr' => 'İstemci ekle'], 'inputs' => [['key' => 'name', 'type' => 'slug', 'label' => ['en' => 'Client name', 'tr' => 'İstemci adı'], 'required' => true, 'default' => null, 'options' => null, 'min' => null, 'max' => null, 'max_length' => 32]], 'outputs' => []],
            ],
        ], $overrides);
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
            'progress' => 100,
            'step' => 'Done',
            'error' => null,
            'inputs' => ['name' => 'laptop'],
            'outputs' => [
                ['key' => 'client_config', 'type' => 'file', 'label' => ['en' => 'Client configuration'], 'secret' => true, 'value' => null, 'filename' => 'client.conf', 'content_base64' => base64_encode("[Interface]\nPrivateKey = laptop-key\n")],
            ],
        ], $overrides);
    }

    public function test_the_overview_shows_actions_uninstall_and_recent_runs()
    {
        $this->showsServer();
        $this->freshApi();
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => [self::installation()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs', 200, ['data' => [self::appRun(), self::appRun(['id' => 'uninstall-uuid', 'kind' => 'uninstall', 'action' => null, 'status' => 'failed', 'error' => 'systemctl failed', 'outputs' => []])]]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);

        $installation = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx']['installations'][0];

        $this->assertTrue($installation['canUninstall']);
        $this->assertSame('İstemci ekle', $installation['actions'][0]['label']);
        $this->assertSame('İstemci adı', $installation['actions'][0]['inputs'][0]['label']);
        $this->assertSame(['id' => 'run-uuid', 'label' => 'İstemci ekle', 'status' => 'succeeded', 'statusLabel' => 'Tamamlandı', 'busy' => false, 'failed' => false, 'canCancel' => false, 'error' => null], array_diff_key($installation['runs'][0], ['outputs' => true]));
        $this->assertSame('client.conf', $installation['runs'][0]['outputs'][0]['filename']);
        $this->assertSame(['Kaldır', 'systemctl failed'], [$installation['runs'][1]['label'], $installation['runs'][1]['error']]);
        $this->assertFalse($installation['runBusy']);
    }

    public function test_an_app_is_uninstalled_and_an_action_runs_with_its_inputs()
    {
        $this->serviceHas($this->server());
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/apps/installation-uuid', 202, ['data' => self::appRun(['kind' => 'uninstall', 'status' => 'running'])]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/actions/add_client', 202, ['data' => self::appRun(['status' => 'running', 'outputs' => null])]);

        $this->assertSame('success', $this->module()->uninstallApp(['installation' => 'installation-uuid']));
        $this->assertStringStartsWith('whmcs-42-app-uninstall-', $this->api->last('DELETE /servers/' . self::SERVER_ID . '/apps/installation-uuid')['headers']['Idempotency-Key']);

        $this->assertSame('success', $this->module()->runAppAction(['installation' => 'installation-uuid', 'app_action' => 'add_client', 'inputs' => ['name' => ' laptop ', 'empty' => '']]));
        $request = $this->api->last('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/actions/add_client');
        $this->assertSame(['inputs' => ['name' => 'laptop']], $request['body']);
        $this->assertStringStartsWith('whmcs-42-app-action-', $request['headers']['Idempotency-Key']);

        $this->assertSame('Please choose an action.', $this->module()->runAppAction(['installation' => 'installation-uuid', 'app_action' => '../reboot']));
        $this->assertStringNotContainsString('laptop', (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']), 'Action inputs are masked in the module log.');
    }

    public function test_an_offered_upgrade_is_shown_and_sent_with_the_new_inputs()
    {
        $upgrade = ['version' => '1.4.0', 'inputs' => [['key' => 'dns', 'type' => 'domain', 'label' => ['en' => 'DNS server', 'tr' => 'DNS sunucusu'], 'required' => true, 'default' => null, 'options' => null, 'min' => null, 'max' => null, 'max_length' => null]]];
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => [self::installation(['upgrade' => $upgrade]), self::installation(['id' => 'failed-uuid', 'status' => 'upgrade_failed', 'error' => 'apt failed', 'upgrade' => null])]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs', 200, ['data' => [self::appRun(['kind' => 'upgrade', 'action' => null, 'target_version' => '1.4.0', 'outputs' => []])]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/failed-uuid/runs', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/upgrade', 202, ['data' => self::appRun(['kind' => 'upgrade', 'action' => null, 'status' => 'running', 'outputs' => null])]);

        $installations = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx']['installations'];

        $this->assertSame('1.4.0', $installations[0]['upgrade']['version']);
        $this->assertSame('1.4.0 sürümüne yükselt', $installations[0]['upgrade']['label']);
        $this->assertSame('DNS sunucusu', $installations[0]['upgrade']['inputs'][0]['label']);
        $this->assertSame('1.4.0 sürümüne yükselt', $installations[0]['runs'][0]['label']);
        $this->assertNull($installations[1]['upgrade']);
        $this->assertSame(['Yükseltme başarısız', true, false], [$installations[1]['statusLabel'], $installations[1]['danger'], $installations[1]['failed']]);

        $this->assertSame('success', $this->module()->upgradeApp(['installation' => 'installation-uuid', 'inputs' => ['dns' => ' dns.example.com ', 'empty' => '']]));
        $request = $this->api->last('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/upgrade');
        $this->assertSame(['inputs' => ['dns' => 'dns.example.com']], $request['body']);
        $this->assertStringStartsWith('whmcs-42-app-upgrade-', $request['headers']['Idempotency-Key']);
        $this->assertStringNotContainsString('dns.example.com', (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']), 'Upgrade inputs are masked in the module log.');
        $this->assertContains('UpgradeApp', proxmotion_ClientAreaAllowedFunctions());
    }

    public function test_a_file_output_of_a_run_is_downloaded_and_never_logged()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs/run-uuid', 200, ['data' => self::appRun()]);

        $file = $this->module()->appFile('installation-uuid', 'client_config', 'run-uuid');

        $this->assertSame(['filename' => 'client.conf', 'content' => "[Interface]\nPrivateKey = laptop-key\n"], $file);
        $this->assertStringNotContainsString(base64_encode("[Interface]\nPrivateKey = laptop-key\n"), (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']));
    }

    public function test_the_overview_lists_installed_and_installable_apps_in_the_customers_language()
    {
        $this->showsServer();
        $this->freshApi();
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => [self::installation()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [self::wireguard()]]);

        $vars = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx'];

        $this->assertSame('Kuruldu', $vars['installations'][0]['statusLabel']);
        $this->assertSame(['key' => 'client_config', 'label' => 'İstemci yapılandırması', 'file' => true, 'url' => false, 'value' => null, 'filename' => 'client.conf'], $vars['installations'][0]['outputs'][0]);
        $this->assertSame('Server endpoint', $vars['installations'][0]['outputs'][1]['label'], 'English without a Turkish text.');
        $this->assertSame('203.0.113.10:51820', $vars['installations'][0]['outputs'][1]['value']);
        $this->assertSame(['key' => 'port', 'type' => 'port', 'label' => 'UDP portu', 'required' => false, 'default' => '51820', 'options' => [], 'min' => 1, 'max' => 65535], $vars['apps'][0]['inputs'][0]);
        $this->assertFalse($vars['appsBusy']);
    }

    public function test_an_app_is_installed_with_the_filled_in_inputs()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps', 202, ['data' => self::installation(['status' => 'installing', 'outputs' => null])]);

        $this->assertSame('success', $this->module()->installApp(['app' => 'openvpn', 'inputs' => ['port' => ' 443 ', 'protocol' => 'tcp', 'empty' => '', 'nested' => ['x']]]));

        $request = $this->api->last('POST /servers/' . self::SERVER_ID . '/apps');
        $this->assertSame(['app' => 'openvpn', 'inputs' => ['port' => '443', 'protocol' => 'tcp']], $request['body']);
        $this->assertStringStartsWith('whmcs-42-app-', $request['headers']['Idempotency-Key']);
        $this->assertSame('Please choose an app.', $this->module()->installApp(['app' => '']));
    }

    public function test_a_file_output_is_fetched_when_downloaded_and_never_logged()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps/installation-uuid', 200, ['data' => self::installation()]);

        $file = $this->module()->appFile('installation-uuid', 'client_config');

        $this->assertSame(['filename' => 'client.conf', 'content' => "[Interface]\nPrivateKey = secret\n"], $file);
        $this->assertNull($this->module()->appFile('installation-uuid', 'endpoint'), 'Not a file.');
        $this->assertStringNotContainsString(base64_encode("[Interface]\nPrivateKey = secret\n"), (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']));
        $this->assertStringNotContainsString('203.0.113.10:51820', (string) json_encode($GLOBALS['whmcs_calls']['logModuleCall']));
    }

    public function test_a_failed_installation_can_be_retried()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/retry', 202, ['data' => self::installation(['status' => 'installing'])]);

        $this->assertSame('success', $this->module()->retryApp(['installation' => 'installation-uuid']));
        $this->assertSame(['POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/retry'], array_values(array_filter($this->api->sent(), function ($route) {
            return strpos($route, '/retry') !== false;
        })));
    }

    public function test_a_running_installation_or_run_can_be_cancelled()
    {
        $this->serviceHas($this->server(['status' => 'suspended']));
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/cancel', 202, ['data' => self::installation(['status' => 'failed'])]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs/run-uuid/cancel', 202, ['data' => self::appRun(['status' => 'failed'])]);

        // Also while the server is not active: cancelling only stops a script.
        $this->assertSame('success', $this->module()->cancelApp(['installation' => 'installation-uuid']));
        $this->assertSame('success', $this->module()->cancelApp(['installation' => 'installation-uuid', 'run' => 'run-uuid']));
        $this->assertSame([
            'POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/cancel',
            'POST /servers/' . self::SERVER_ID . '/apps/installation-uuid/runs/run-uuid/cancel',
        ], array_values(array_filter($this->api->sent(), function ($route) {
            return strpos($route, '/cancel') !== false;
        })));
    }

    public function test_the_overview_shows_the_server()
    {
        $this->showsServer();

        $result = $this->module()->clientArea();

        $this->assertSame('templates/overview.tpl', $result['tabOverviewReplacementTemplate']);
        $vars = $result['templateVariables']['pmx'];
        $this->assertNull($vars['error']);
        $this->assertSame('Active', $vars['server']['statusLabel']);
        $this->assertSame('Running', $vars['server']['powerLabel']);
        $this->assertSame('2 vCPU', $vars['server']['cpu']);
        $this->assertSame('4 GB RAM', $vars['server']['memory']);
        $this->assertSame('40 GB disk', $vars['server']['disk']);
        $this->assertSame([['address' => '203.0.113.10', 'version' => 4, 'ptr' => null]], $vars['server']['addresses']);
        $this->assertSame('Ubuntu 24.04', $vars['osTemplates'][0]['name']);
        $this->assertTrue($vars['server']['active']);
        $this->assertFalse($vars['server']['busy']);
    }

    public function test_the_overview_tells_when_a_restart_is_needed()
    {
        $this->showsServer(['reboot_required' => true]);

        $this->assertTrue($this->module()->clientArea()['templateVariables']['pmx']['server']['rebootRequired']);
    }

    public function test_the_overview_is_in_the_customers_language()
    {
        $this->showsServer(['status' => 'suspended', 'power_state' => null]);

        $vars = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx'];

        $this->assertSame('Askıya alındı', $vars['server']['statusLabel']);
        $this->assertSame('Konsolu aç', $vars['lang']['console']);
        $this->assertSame([], $vars['osTemplates'], 'Reinstalling needs an active server.');
    }

    public function test_every_turkish_text_exists_in_english_and_the_other_way_round()
    {
        $english = require __DIR__ . '/../modules/servers/proxmotion/lang/english.php';
        $turkish = require __DIR__ . '/../modules/servers/proxmotion/lang/turkish.php';

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($turkish))), 'Missing in turkish.php');
        $this->assertSame([], array_values(array_diff(array_keys($turkish), array_keys($english))), 'Missing in english.php');
        $this->assertSame('Server', Lang::load('klingon')->get('title'), 'Unknown languages fall back to English.');
        $this->assertSame('Server', Lang::load('../../etc/passwd')->get('title'));
    }

    public function test_a_new_root_password_is_moved_to_the_whmcs_service()
    {
        $this->showsServer(['root_password_available' => true]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 200, ['data' => ['root_password' => 'new-from-proxmotion-1']]);

        $this->module()->clientArea();

        $this->assertSame([['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'servicepassword' => 'new-from-proxmotion-1']]], $this->localApiCalls());
    }

    public function test_problems_are_shown_without_details()
    {
        $this->serviceHas(null);
        $this->assertSame('The server of this service was not found. Please contact support.', $this->module()->clientArea()['templateVariables']['pmx']['error']);

        $this->freshApi();
        $this->api->on('GET /servers?external_id=42', 503, ['message' => 'Proxmox could not be reached.']);
        $this->assertSame('The server details cannot be shown right now. Please try again later.', $this->module()->clientArea()['templateVariables']['pmx']['error']);
    }

    public function test_power_actions()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/power', 202, $this->task('reboot'));

        $this->assertSame('success', $this->module()->power('reboot'));
        $this->assertSame(['action' => 'reboot'], $this->api->last('POST /servers/' . self::SERVER_ID . '/power')['body']);
    }

    public function test_actions_need_an_active_server()
    {
        $this->serviceHas($this->server(['status' => 'suspended']));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('This is only possible while the server is active.');

        $this->module()->power('start');
    }

    public function test_the_console_link_comes_from_proxmotion()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/console', 201, ['data' => ['url' => 'https://panel.example.com/console/x/link/y?signature=z', 'expires_at' => '2026-10-16T10:00:00+00:00']]);

        $this->assertSame('https://panel.example.com/console/x/link/y?signature=z', $this->module()->consoleUrl());
    }

    public function test_a_reset_password_is_waited_for_and_stored_on_the_service()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));
        $this->api->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'running'))
            ->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'succeeded'));
        $this->api->on('GET /servers/' . self::SERVER_ID . '/password', 200, ['data' => ['root_password' => 'random-from-proxmotion']]);

        $this->assertSame('success', $this->module()->resetPassword());

        $this->assertSame([], $this->api->last('POST /servers/' . self::SERVER_ID . '/password')['body'], 'A random password.');
        $this->assertSame('random-from-proxmotion', $this->localApiCalls()[0]['values']['servicepassword']);
    }

    public function test_a_slow_reset_leaves_the_password_for_the_next_overview()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/password', 202, $this->task('reset_password'));
        $this->api->on('GET /tasks/task-uuid', 200, $this->task('reset_password', 'running'));

        $this->assertSame('success', $this->module()->resetPassword());

        $this->assertSame([], $this->localApiCalls());
        $this->assertNotContains('GET /servers/' . self::SERVER_ID . '/password', $this->api->sent());
    }

    public function test_reinstalling_needs_the_confirmation()
    {
        $this->assertSame('Please confirm that all data may be deleted.', $this->module()->reinstall(['os_template' => 'os-uuid']));
        $this->assertSame([], $this->api->sent());

        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/reinstall', 202, $this->task('reinstall'));

        $this->assertSame('success', $this->module()->reinstall(['os_template' => 'os-uuid', 'ssh_key' => 'ssh-ed25519 AAAA user@example.com', 'confirm' => '1']));
        $this->assertSame(['os_template' => 'os-uuid', 'ssh_keys' => ['ssh-ed25519 AAAA user@example.com']], $this->api->last('POST /servers/' . self::SERVER_ID . '/reinstall')['body']);
    }

    public function test_the_reinstall_form_says_how_many_reinstalls_are_left()
    {
        // docs/adr/0045
        $this->showsServer(['reinstalls' => ['limit' => 3, 'period_days' => 30, 'used' => 1, 'remaining' => 2, 'available_at' => null]]);

        $this->assertSame(
            ['allowed' => true, 'message' => 'Reinstalls left: 2 of 3 in 30 days.'],
            $this->module()->clientArea()['templateVariables']['pmx']['server']['reinstalls']
        );
    }

    public function test_the_reinstall_form_is_replaced_once_no_reinstalls_are_left()
    {
        $this->showsServer(['reinstalls' => ['limit' => 2, 'period_days' => 30, 'used' => 2, 'remaining' => 0, 'available_at' => '2026-11-26T12:00:00+03:00']]);

        $this->assertSame(
            ['allowed' => false, 'message' => 'You have used all 2 reinstalls of the last 30 days. The next reinstall is possible after 2026-11-26 12:00.'],
            $this->module()->clientArea()['templateVariables']['pmx']['server']['reinstalls']
        );
    }

    public function test_reinstalls_that_are_not_allowed()
    {
        $this->showsServer(['reinstalls' => ['limit' => 0, 'period_days' => 30, 'used' => 0, 'remaining' => 0, 'available_at' => null]]);

        $this->assertSame(
            ['allowed' => false, 'message' => 'This server cannot be reinstalled here. Please contact support.'],
            $this->module()->clientArea()['templateVariables']['pmx']['server']['reinstalls']
        );
    }

    public function test_unlimited_reinstalls_show_no_note()
    {
        $this->showsServer(['reinstalls' => ['limit' => null, 'period_days' => null, 'used' => null, 'remaining' => null, 'available_at' => null]]);

        $this->assertNull($this->module()->clientArea()['templateVariables']['pmx']['server']['reinstalls']);
    }

    public function test_an_older_panel_without_reinstall_limits_shows_no_note()
    {
        $this->showsServer();

        $this->assertNull($this->module()->clientArea()['templateVariables']['pmx']['server']['reinstalls']);
    }

    public function test_a_refused_reinstall_shows_proxmotions_reason()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/reinstall', 409, [
            'message' => 'The reinstall limit of this server (2 in 30 days) has been reached. It can be reinstalled again after 2026-11-26 09:00 UTC.',
            'error' => 'reinstall_limit_reached',
            'available_at' => '2026-11-26T12:00:00+03:00',
        ]);

        try {
            $this->module()->reinstall(['os_template' => 'os-uuid', 'confirm' => '1']);
            $this->fail('The reinstall was refused.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status());
            $this->assertStringContainsString('The reinstall limit of this server (2 in 30 days) has been reached.', $e->describe());
        }
    }

    public function test_a_reinstall_can_bring_an_app()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/reinstall', 202, $this->task('reinstall'));

        $this->module()->reinstall(['os_template' => 'os-uuid', 'app' => ' docker ', 'confirm' => '1']);

        $this->assertSame(['os_template' => 'os-uuid', 'app' => 'docker'], $this->api->last('POST /servers/' . self::SERVER_ID . '/reinstall')['body']);
    }

    public function test_the_reinstall_form_offers_apps_the_server_may_install_with_their_defaults()
    {
        $this->showsServer();
        $this->freshApi();
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04']]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $required = ['key' => 'domain', 'type' => 'domain', 'label' => null, 'required' => true, 'default' => null];
        $this->api->on('GET /apps?mode=order&server=' . self::SERVER_ID, 200, ['data' => [
            self::wireguard() + ['billing' => 'free', 'entitled' => true],
            ['id' => 'cpanel', 'name' => 'cPanel', 'inputs' => [], 'billing' => 'paid', 'entitled' => false],
            ['id' => 'wordpress', 'name' => 'WordPress', 'inputs' => [$required], 'billing' => 'free', 'entitled' => true],
        ]]);

        $vars = $this->module()->clientArea()['templateVariables']['pmx'];

        $this->assertSame([['id' => 'wireguard', 'name' => 'WireGuard']], $vars['reinstallApps']);
    }

    public function test_paid_apps_without_a_right_are_offered_to_buy()
    {
        $this->showsServer();
        $this->freshApi();
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=9b2f2c1e-0000-4000-8000-000000000001', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => [
            ['id' => 'cpanel', 'name' => 'cPanel', 'inputs' => [], 'billing' => 'paid', 'license' => 'external', 'entitled' => false],
            ['id' => 'plesk', 'name' => 'Plesk', 'inputs' => [], 'billing' => 'paid', 'license' => 'external', 'entitled' => true],
        ]]);

        $vars = $this->module()->clientArea()['templateVariables']['pmx'];

        $this->assertTrue($vars['apps'][0]['buy']);
        $this->assertFalse($vars['apps'][1]['buy']);
        $this->assertTrue($vars['apps'][1]['licence_required']);
        $this->assertSame([], $vars['reinstallApps'], 'No reinstall form without templates.');
        $this->assertSame('cart.php?gid=addons&pid=42', $vars['addonsUrl']);
    }

    public function test_reverse_dns_is_set_and_removed()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/rdns', 200, ['data' => ['address' => '203.0.113.10', 'ptr' => 'mail.example.com']]);

        $this->module()->setRdns(['address' => '203.0.113.10', 'hostname' => ' mail.example.com ']);
        $this->assertSame(['address' => '203.0.113.10', 'hostname' => 'mail.example.com'], $this->api->last('POST /servers/' . self::SERVER_ID . '/rdns')['body']);

        $this->module()->setRdns(['address' => '203.0.113.10', 'hostname' => '']);
        $this->assertSame(['address' => '203.0.113.10', 'hostname' => null], $this->api->last('POST /servers/' . self::SERVER_ID . '/rdns')['body']);
    }

    public function test_the_server_is_renamed_and_the_service_domain_follows()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/hostname', 202, $this->task('change_hostname'));

        $this->assertSame('success', $this->module()->changeHostname(['hostname' => ' Web2.Example.com ', 'reboot' => '1']));

        $this->assertSame(['hostname' => 'web2.example.com', 'reboot' => true], $this->api->last('POST /servers/' . self::SERVER_ID . '/hostname')['body']);
        $this->assertSame([['command' => 'UpdateClientProduct', 'values' => ['serviceid' => 42, 'domain' => 'web2.example.com']]], $GLOBALS['whmcs_calls']['localAPI']);
        $this->assertSame('Please enter a hostname.', $this->module()->changeHostname(['hostname' => ' ']));
    }

    public function test_the_admin_tab_links_to_the_panel()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server(['addresses' => [['address' => '203.0.113.10', 'version' => 4, 'ptr' => 'web1.example.com']]])]);

        $fields = $this->module()->adminServiceFields();

        $this->assertStringContainsString('href="https://panel.example.com/admin/servers/' . self::SERVER_ID . '"', $fields['Proxmotion server']);
        $this->assertSame('active (running)', $fields['Server status']);
        $this->assertSame('203.0.113.10 (web1.example.com)', $fields['IP addresses']);
    }

    public function test_the_whmcs_functions_are_complete()
    {
        foreach (['MetaData', 'ConfigOptions', 'TestConnection', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount', 'ChangePassword', 'ChangePackage', 'ClientArea', 'AdminServicesTabFields', 'AdminCustomButtonArray', 'ClientAreaAllowedFunctions'] as $function) {
            $this->assertTrue(function_exists('proxmotion_' . $function), "proxmotion_{$function} is missing.");
        }

        foreach (array_merge(proxmotion_ClientAreaAllowedFunctions(), array_values(proxmotion_AdminCustomButtonArray())) as $function) {
            $this->assertTrue(function_exists('proxmotion_' . $function), "proxmotion_{$function} is listed but missing.");
        }

        $this->assertSame(['Package', 'Location', 'OS template', 'Network model', 'Restart after upgrades', 'Paid app addons'], array_keys(proxmotion_ConfigOptions()));
        $this->assertTrue(is_file(__DIR__ . '/../modules/servers/proxmotion/templates/overview.tpl'));
    }
}
