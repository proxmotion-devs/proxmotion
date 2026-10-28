<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;

/**
 * SSH keys in the client area (docs/adr/0049): listed, and every change sent
 * to Proxmotion as the whole list of keys.
 */
class SshKeysTest extends ModuleTestCase
{
    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ test@example.com';

    private const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBI2I8EdbS3Slp/IN7K845sd1LBKz/KLHrjRe0hIpaREfov0LnaiW6Z+PFrP7Glf+rqpkk7fiZ541a2Fe1Qv6wP8=';

    /**
     * @return array<string, mixed>
     */
    private static function sshKeys(): array
    {
        return [
            'ssh_keys' => [
                ['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:ed', 'comment' => 'test@example.com', 'public_key' => self::ED25519],
                ['type' => 'ecdsa-sha2-nistp256', 'fingerprint' => 'SHA256:ec', 'comment' => '', 'public_key' => self::ECDSA],
            ],
            'max_keys' => 20,
        ];
    }

    private function hasKeys(): void
    {
        $this->serviceHas($this->server());
        $this->api = new FakeTransport();
        $this->api->on('GET /servers/' . self::SERVER_ID . '/ssh-keys', 200, ['data' => self::sshKeys()]);
        $this->api->on('PUT /servers/' . self::SERVER_ID . '/ssh-keys', 202, ['data' => ['id' => 'task-uuid', 'type' => 'change_ssh_keys', 'status' => 'queued']]);
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
    }

    /**
     * @return list<string>|null
     */
    private function sentKeys(): ?array
    {
        return $this->api->last('PUT /servers/' . self::SERVER_ID . '/ssh-keys')['body']['ssh_keys'];
    }

    public function test_the_overview_lists_the_keys()
    {
        $this->serviceHas($this->server());
        $this->api = new FakeTransport();
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/firewall', 200, ['data' => ['default_policy' => 'accept', 'rules' => [], 'app_rules' => []]]);
        $this->api->on('GET /firewall-rulesets', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/ssh-keys', 200, ['data' => self::sshKeys()]);

        $keys = $this->module()->clientArea()['templateVariables']['pmx']['sshKeys'];

        $this->assertFalse($keys['full']);
        $this->assertSame([
            ['fingerprint' => 'SHA256:ed', 'type' => 'ssh-ed25519', 'comment' => 'test@example.com'],
            ['fingerprint' => 'SHA256:ec', 'type' => 'ecdsa-sha2-nistp256', 'comment' => ''],
        ], $keys['keys']);
    }

    public function test_a_panel_without_ssh_keys_hides_the_section()
    {
        $this->serviceHas($this->server());
        $this->api = new FakeTransport();
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $this->server()]);
        $this->api->on('GET /os-templates?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/firewall', 200, ['data' => ['default_policy' => 'accept', 'rules' => [], 'app_rules' => []]]);
        $this->api->on('GET /firewall-rulesets', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/ssh-keys', 404, ['message' => 'Not found.']);

        $vars = $this->module()->clientArea()['templateVariables']['pmx'];

        $this->assertNull($vars['sshKeys']);
        $this->assertNull($vars['error']);
    }

    public function test_a_key_is_added_after_the_others()
    {
        $this->hasKeys();

        $this->assertSame('success', $this->module()->sshKeys(['op' => 'add', 'public_key' => "  ssh-rsa AAAAB3 new@example.com \n"]));

        $this->assertSame([self::ED25519, self::ECDSA, 'ssh-rsa AAAAB3 new@example.com'], $this->sentKeys());
        $this->assertStringStartsWith('whmcs-42-ssh-keys-', $this->api->last('PUT /servers/' . self::SERVER_ID . '/ssh-keys')['headers']['Idempotency-Key']);
    }

    public function test_a_key_is_deleted_by_its_fingerprint()
    {
        $this->hasKeys();

        $this->assertSame('success', $this->module()->sshKeys(['op' => 'delete', 'fingerprint' => 'SHA256:ed']));

        $this->assertSame([self::ECDSA], $this->sentKeys());
    }

    public function test_empty_and_unknown_changes_are_refused()
    {
        $this->hasKeys();

        $this->assertSame('Please paste a public SSH key.', $this->module()->sshKeys(['op' => 'add', 'public_key' => ' ']));
        $this->assertSame('This SSH key change is not possible.', $this->module()->sshKeys(['op' => 'delete', 'fingerprint' => 'SHA256:other']));
        $this->assertSame('This SSH key change is not possible.', $this->module()->sshKeys(['op' => 'replace']));
        $this->assertNotContains('PUT /servers/' . self::SERVER_ID . '/ssh-keys', $this->api->sent());
    }

    public function test_the_panel_s_refusal_is_shown()
    {
        $this->serviceHas($this->server());
        $this->api = new FakeTransport();
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/ssh-keys', 200, ['data' => self::sshKeys()]);
        $this->api->on('PUT /servers/' . self::SERVER_ID . '/ssh-keys', 422, ['message' => 'Unsupported SSH key type.', 'errors' => ['ssh_keys.2' => ['Unsupported SSH key type. Use ed25519, ECDSA or RSA (2048 bits or more).']]]);

        try {
            $this->module()->sshKeys(['op' => 'add', 'public_key' => 'ssh-dss AAAAB3 old@example.com']);
            $this->fail('The refusal was not passed on.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Unsupported SSH key type', $e->describe());
        }
    }
}
