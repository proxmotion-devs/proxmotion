<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;

/**
 * The firewall in the client area (docs/adr/0035): shown with its rules,
 * and every change sent to Proxmotion as the whole list of rules.
 */
class FirewallTest extends ModuleTestCase
{
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
        // Answers are used in order: replace the default empty firewall.
        $this->api = new FakeTransport();
        $this->api->on('GET /servers/' . self::SERVER_ID . '/firewall', 200, ['data' => self::firewall()]);
        $this->api->on('PUT /servers/' . self::SERVER_ID . '/firewall', 202, ['data' => ['id' => 'task-uuid', 'type' => 'apply_firewall', 'status' => 'queued']]);
        $this->api->on('GET /servers', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sentRules(): ?array
    {
        return $this->api->last('PUT /servers/' . self::SERVER_ID . '/firewall')['body'];
    }

    public function test_the_overview_shows_the_firewall_with_its_rules_and_rule_sets()
    {
        $this->serviceHas($this->server());
        $this->api = new FakeTransport();
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/firewall', 200, ['data' => self::firewall()]);
        $this->api->on('GET /firewall-rulesets', 200, ['data' => [['id' => 'ruleset-uuid', 'name' => 'Web', 'description' => null, 'rules' => []]]]);

        $firewall = $this->module()->clientArea()['templateVariables']['pmx']['firewall'];

        $this->assertTrue($firewall['enabled']);
        $this->assertSame(['index' => 0, 'action' => 'Allow', 'allows' => true, 'protocol' => 'TCP', 'port' => '22', 'source' => '198.51.100.0/24', 'comment' => 'Office', 'first' => true, 'last' => false], $firewall['rules'][0]);
        $this->assertSame(['Block', 'Any', 'all ports', 'anywhere', true], [$firewall['rules'][1]['action'], $firewall['rules'][1]['protocol'], $firewall['rules'][1]['port'], $firewall['rules'][1]['source'], $firewall['rules'][1]['last']]);
        $this->assertSame([['app' => 'wireguard', 'port' => 51820, 'protocol' => 'UDP']], $firewall['appRules']);
        $this->assertSame([['id' => 'ruleset-uuid', 'name' => 'Web', 'description' => '']], $firewall['rulesets']);
    }

    public function test_a_rule_is_added_after_the_others()
    {
        $this->hasFirewall();

        $result = $this->module()->firewall(['op' => 'add', 'rule_action' => 'accept', 'protocol' => 'udp', 'port' => ' 53 ', 'source' => '', 'comment' => 'DNS']);

        $this->assertSame('success', $result);
        $body = $this->sentRules();
        $this->assertTrue($body['enabled']);
        $this->assertCount(3, $body['rules']);
        $this->assertSame(['action' => 'accept', 'protocol' => 'udp', 'port' => '53', 'source' => null, 'comment' => 'DNS'], $body['rules'][2]);
        $this->assertStringStartsWith('whmcs-42-firewall-', $this->api->last('PUT /servers/' . self::SERVER_ID . '/firewall')['headers']['Idempotency-Key']);
    }

    public function test_icmp_and_any_rules_lose_their_port()
    {
        $this->hasFirewall();

        $this->module()->firewall(['op' => 'add', 'rule_action' => 'accept', 'protocol' => 'icmp', 'port' => '22']);

        $this->assertNull($this->sentRules()['rules'][2]['port']);
    }

    public function test_rules_are_moved_and_deleted_by_position()
    {
        $this->hasFirewall();

        $this->module()->firewall(['op' => 'down', 'index' => '0']);
        $this->assertSame(['drop', 'accept'], array_column($this->sentRules()['rules'], 'action'));

        $this->module()->firewall(['op' => 'delete', 'index' => '1']);
        $this->assertSame(['accept'], array_column($this->sentRules()['rules'], 'action'));
    }

    public function test_the_firewall_is_turned_off_keeping_its_rules()
    {
        $this->hasFirewall();

        $this->module()->firewall(['op' => 'toggle', 'enabled' => '0']);

        $this->assertFalse($this->sentRules()['enabled']);
        $this->assertCount(2, $this->sentRules()['rules']);
    }

    public function test_a_rule_set_is_applied()
    {
        $this->hasFirewall();
        $this->api->on('POST /servers/' . self::SERVER_ID . '/firewall/ruleset', 202, ['data' => ['id' => 'task-uuid', 'type' => 'apply_firewall', 'status' => 'queued']]);

        $this->assertSame('Please choose a rule set.', $this->module()->firewall(['op' => 'ruleset', 'ruleset' => '']));
        $this->assertSame('success', $this->module()->firewall(['op' => 'ruleset', 'ruleset' => 'ruleset-uuid', 'mode' => 'append']));

        $this->assertSame(['ruleset' => 'ruleset-uuid', 'mode' => 'append'], $this->api->last('POST /servers/' . self::SERVER_ID . '/firewall/ruleset')['body']);
    }

    public function test_unknown_changes_are_refused()
    {
        $this->hasFirewall();

        $this->assertSame('This firewall change is not possible.', $this->module()->firewall(['op' => 'delete', 'index' => '7']));
        $this->assertNotContains('PUT /servers/' . self::SERVER_ID . '/firewall', $this->api->sent());
    }

    public function test_validation_errors_of_proxmotion_are_shown()
    {
        $this->hasFirewall();
        $this->api = new FakeTransport();
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/firewall', 200, ['data' => self::firewall()]);
        $this->api->on('PUT /servers/' . self::SERVER_ID . '/firewall', 422, ['message' => 'Enter a port or a range such as 1000-2000.', 'errors' => ['rules.2.port' => ['Enter a port or a range such as 1000-2000.']]]);

        try {
            $this->module()->firewall(['op' => 'add', 'rule_action' => 'accept', 'protocol' => 'tcp', 'port' => '99999']);
            $this->fail('The 422 answer is thrown.');
        } catch (ApiException $e) {
            // proxmotion_run() shows describe() to the customer.
            $this->assertStringContainsString('Enter a port or a range such as 1000-2000.', $e->describe());
        }
    }
}
