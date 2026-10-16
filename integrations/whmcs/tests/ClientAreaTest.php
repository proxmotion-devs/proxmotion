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
        $this->api->on('GET /os-templates', 200, ['data' => [['id' => 'os-uuid', 'name' => 'Ubuntu 24.04', 'family' => 'ubuntu', 'version' => '24.04']]]);
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

        $this->api = new FakeTransport();
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

    public function test_reverse_dns_is_set_and_removed()
    {
        $this->serviceHas($this->server());
        $this->api->on('POST /servers/' . self::SERVER_ID . '/rdns', 200, ['data' => ['address' => '203.0.113.10', 'ptr' => 'mail.example.com']]);

        $this->module()->setRdns(['address' => '203.0.113.10', 'hostname' => ' mail.example.com ']);
        $this->assertSame(['address' => '203.0.113.10', 'hostname' => 'mail.example.com'], $this->api->last('POST /servers/' . self::SERVER_ID . '/rdns')['body']);

        $this->module()->setRdns(['address' => '203.0.113.10', 'hostname' => '']);
        $this->assertSame(['address' => '203.0.113.10', 'hostname' => null], $this->api->last('POST /servers/' . self::SERVER_ID . '/rdns')['body']);
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

        $this->assertSame(['Package', 'Location', 'OS template', 'Network model'], array_keys(proxmotion_ConfigOptions()));
        $this->assertTrue(is_file(__DIR__ . '/../modules/servers/proxmotion/templates/overview.tpl'));
    }
}
