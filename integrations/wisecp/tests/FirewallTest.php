<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Exception;

/**
 * The Firewall tool (docs/adr/0035): the page, and every change sent to
 * Proxmotion as the whole list of rules.
 */
final class FirewallTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    /**
     * @return array<string, mixed>
     */
    private static function firewall(): array
    {
        return [
            'enabled' => true,
            'rules' => [
                ['action' => 'accept', 'protocol' => 'tcp', 'port' => '22', 'source' => '198.51.100.0/24', 'comment' => 'Office'],
                ['action' => 'drop', 'protocol' => 'any', 'port' => null, 'source' => null, 'comment' => null],
            ],
            'app_rules' => [['app' => 'wireguard', 'protocol' => 'udp', 'port' => 51820]],
        ];
    }

    private function hasFirewall(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/firewall', 200, ['data' => self::firewall()]);
        $this->api->on('PUT ' . self::SERVER . '/firewall', 202, ['data' => ['id' => 'task-uuid', 'type' => 'apply_firewall', 'status' => 'queued']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sent(): array
    {
        return (array) $this->api->last('PUT ' . self::SERVER . '/firewall')['body'];
    }

    public function test_the_tool_is_added_and_hidden_unless_the_server_is_active(): void
    {
        $tool = $this->module()->get_tool('firewall');

        $this->assertNotNull($tool);
        $this->assertSame(['Firewall', 'firewall', 'page-loader'], [$tool['label'], $tool['page'], $tool['type']]);

        $suspended = $this->module();
        $suspended->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'suspended']]]);
        $this->assertContains('firewall', $suspended->removedTools);
    }

    public function test_the_page_data_has_the_rules_app_ports_and_rule_sets(): void
    {
        $this->hasFirewall();
        $this->api->on('GET /firewall-rulesets', 200, ['data' => [['id' => 'ruleset-uuid', 'name' => 'Web', 'description' => null, 'rules' => []]]]);

        $data = $this->module()->tool_data('firewall', 'index');

        $this->assertTrue($data['enabled']);
        $this->assertSame(self::firewall()['rules'], $data['rules']);
        $this->assertSame([['app' => 'wireguard', 'protocol' => 'udp', 'port' => 51820]], $data['app_rules']);
        $this->assertSame([['id' => 'ruleset-uuid', 'name' => 'Web', 'description' => '']], $data['rulesets']);
    }

    public function test_the_page_shows_the_rules_and_the_forms(): void
    {
        $html = $this->page(['enabled' => true, 'rules' => self::firewall()['rules'], 'app_rules' => self::firewall()['app_rules'], 'rulesets' => [['id' => 'ruleset-uuid', 'name' => 'Web', 'description' => '']]]);

        $this->assertStringContainsString('<code>198.51.100.0/24</code>', $html);
        $this->assertStringContainsString('<code>all ports</code>', $html);
        $this->assertStringContainsString('onclick="pmxFirewallRule(this, \'down\')" data-index="0"', $html);
        $this->assertStringNotContainsString('onclick="pmxFirewallRule(this, \'up\')" data-index="0"', $html, 'The first rule cannot move up.');
        $this->assertStringContainsString('<option value="ruleset-uuid" title="">Web</option>', $html);
        $this->assertStringContainsString('<code>51820/UDP</code> (wireguard)', $html);
        $this->assertStringContainsString('Turn off', $html);

        $empty = $this->page(['enabled' => false, 'rules' => [], 'app_rules' => [], 'rulesets' => []]);
        $this->assertStringContainsString('No rules: nothing can reach the server while the firewall is on.', $empty);
        $this->assertStringNotContainsString('pmxFirewallRuleset(this', $empty);
    }

    public function test_a_rule_is_added_after_the_others(): void
    {
        $this->hasFirewall();

        $result = $this->module()->tool_action('firewall', 'add', ['rule_action' => 'drop', 'protocol' => 'udp', 'port' => ' 53 ', 'source' => '', 'comment' => 'No DNS']);

        $this->assertSame('successful', $result['status']);
        $this->assertTrue($this->sent()['enabled']);
        $this->assertSame(['action' => 'drop', 'protocol' => 'udp', 'port' => '53', 'source' => null, 'comment' => 'No DNS'], $this->sent()['rules'][2]);
        $this->assertMatchesRegularExpression('/^wisecp-42-firewall-/', $this->api->last('PUT ' . self::SERVER . '/firewall')['headers']['Idempotency-Key']);
    }

    public function test_rules_are_moved_and_deleted_and_the_firewall_turned_off(): void
    {
        $this->hasFirewall();

        $this->module()->tool_action('firewall', 'up', ['index' => '1']);
        $this->assertSame(['drop', 'accept'], array_column($this->sent()['rules'], 'action'));

        $this->module()->tool_action('firewall', 'delete', ['index' => '0']);
        $this->assertSame(['drop'], array_column($this->sent()['rules'], 'action'));

        $this->module()->tool_action('firewall', 'toggle', ['enabled' => '0']);
        $this->assertFalse($this->sent()['enabled']);
        $this->assertCount(2, $this->sent()['rules'], 'Turned off, the rules stay.');
    }

    public function test_a_rule_set_is_applied(): void
    {
        $this->hasFirewall();
        $this->api->on('POST ' . self::SERVER . '/firewall/ruleset', 202, ['data' => ['id' => 'task-uuid', 'type' => 'apply_firewall', 'status' => 'queued']]);

        $this->module()->tool_action('firewall', 'ruleset', ['ruleset' => 'ruleset-uuid', 'mode' => 'append']);

        $this->assertSame(['ruleset' => 'ruleset-uuid', 'mode' => 'append'], $this->api->last('POST ' . self::SERVER . '/firewall/ruleset')['body']);
    }

    public function test_impossible_changes_are_refused(): void
    {
        $this->hasFirewall();

        try {
            $this->module()->tool_action('firewall', 'down', ['index' => '1']);
            $this->fail('The last rule cannot move down.');
        } catch (Exception $e) {
            $this->assertSame('This firewall change is not possible.', $e->getMessage());
        }

        $this->expectExceptionMessage('Please choose a rule set.');
        $this->module()->tool_action('firewall', 'ruleset', ['ruleset' => '']);
    }

    public function test_proxmotions_refusal_reaches_the_customer(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/firewall', 200, ['data' => self::firewall()]);
        $this->api->on('PUT ' . self::SERVER . '/firewall', 422, ['message' => 'Enter a port or a range such as 1000-2000.', 'errors' => ['rules.2.port' => ['Enter a port or a range such as 1000-2000.']]]);

        $this->expectExceptionMessage('Enter a port or a range such as 1000-2000.');

        $this->module()->tool_action('firewall', 'add', ['rule_action' => 'accept', 'protocol' => 'tcp', 'port' => '99999']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(array $data): string
    {
        $render = static function (array $vars): string {
            extract($vars);
            ob_start();
            include PROXMOTION_WISECP_MODULE . '/pages/tools/firewall.php';

            return (string) ob_get_clean();
        };

        return $render([
            'data' => $data,
            'error' => null,
            'tool_label' => 'Firewall',
            'lang' => include PROXMOTION_WISECP_MODULE . '/lang/en.php',
        ]);
    }
}
