<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Exception;

/**
 * The SSH keys tool (docs/adr/0049): the page, and every change sent to
 * Proxmotion as the whole list of keys.
 */
final class SshKeysTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    private const ED25519 = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHq0RLG5bN7TQBqYB93lEHfqk4K88b8lHfDZyvpD/FnZ test@example.com';

    private const ECDSA = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBI2I8EdbS3Slp/IN7K845sd1LBKz/KLHrjRe0hIpaREfov0LnaiW6Z+PFrP7Glf+rqpkk7fiZ541a2Fe1Qv6wP8=';

    /**
     * @return array<string, mixed>
     */
    private static function sshKeys(): array
    {
        return [
            'ssh_keys' => [
                ['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:ed+/', 'comment' => 'test@example.com', 'public_key' => self::ED25519],
                ['type' => 'ecdsa-sha2-nistp256', 'fingerprint' => 'SHA256:ec', 'comment' => '', 'public_key' => self::ECDSA],
            ],
            'max_keys' => 2,
        ];
    }

    private function hasKeys(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/ssh-keys', 200, ['data' => self::sshKeys()]);
        $this->api->on('PUT ' . self::SERVER . '/ssh-keys', 202, ['data' => ['id' => 'task-uuid', 'type' => 'change_ssh_keys', 'status' => 'queued']]);
    }

    /**
     * @return list<string>
     */
    private function sent(): array
    {
        $body = $this->api->last('PUT ' . self::SERVER . '/ssh-keys')['body'];
        $keys = is_array($body) && is_array($body['ssh_keys'] ?? null) ? $body['ssh_keys'] : [];

        return array_values(array_filter($keys, 'is_string'));
    }

    public function test_the_tool_is_added_and_hidden_unless_the_server_is_active(): void
    {
        $tool = $this->module()->get_tool('ssh-keys');

        $this->assertNotNull($tool);
        $this->assertSame(['SSH keys', 'ssh-keys', 'page-loader'], [$tool['label'], $tool['page'], $tool['type']]);

        $suspended = $this->module();
        $suspended->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'suspended']]]);
        $this->assertContains('ssh-keys', $suspended->removedTools);
    }

    public function test_the_page_data_lists_the_keys_and_whether_one_more_fits(): void
    {
        $this->hasKeys();

        $data = $this->module()->tool_data('ssh-keys', 'index');

        $this->assertSame([
            ['fingerprint' => 'SHA256:ed+/', 'type' => 'ssh-ed25519', 'comment' => 'test@example.com'],
            ['fingerprint' => 'SHA256:ec', 'type' => 'ecdsa-sha2-nistp256', 'comment' => ''],
        ], $data['keys']);
        $this->assertTrue($data['full']);
    }

    public function test_the_page_shows_the_keys_and_the_form(): void
    {
        $html = $this->page(['keys' => [['fingerprint' => 'SHA256:ed+/', 'type' => 'ssh-ed25519', 'comment' => '<b>me</b>']], 'full' => false]);

        $this->assertStringContainsString('&lt;b&gt;me&lt;/b&gt;', $html);
        $this->assertStringContainsString('data-fingerprint="SHA256:ed+/"', $html);
        $this->assertStringContainsString('name="public_key"', $html);
        $this->assertStringNotContainsString('name="public_key"', $this->page(['keys' => [], 'full' => true]));
        $this->assertStringContainsString('No SSH keys.', $this->page(['keys' => [], 'full' => false]));
    }

    public function test_a_key_is_added_after_the_others(): void
    {
        $this->hasKeys();

        $result = $this->module()->tool_action('ssh-keys', 'add', ['public_key' => " ssh-rsa AAAAB3 new@example.com\n"]);

        $this->assertSame('successful', $result['status']);
        $this->assertSame([self::ED25519, self::ECDSA, 'ssh-rsa AAAAB3 new@example.com'], $this->sent());
    }

    public function test_a_key_is_deleted_by_its_fingerprint(): void
    {
        $this->hasKeys();

        $this->module()->tool_action('ssh-keys', 'delete', ['fingerprint' => 'SHA256:ed+/']);

        $this->assertSame([self::ECDSA], $this->sent());
    }

    public function test_empty_and_unknown_changes_are_refused(): void
    {
        $this->hasKeys();

        foreach ([['add', ['public_key' => ' ']], ['delete', ['fingerprint' => 'SHA256:other']]] as [$action, $data]) {
            try {
                $this->module()->tool_action('ssh-keys', $action, $data);
                $this->fail("The {$action} was accepted.");
            } catch (Exception $e) {
                $this->assertContains($e->getMessage(), ['Please paste a public SSH key.', 'This SSH key change is not possible.']);
            }
        }

        $this->assertNotContains('PUT ' . self::SERVER . '/ssh-keys', $this->api->sent());
    }

    public function test_proxmotions_refusal_reaches_the_customer(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/ssh-keys', 200, ['data' => self::sshKeys()]);
        $this->api->on('PUT ' . self::SERVER . '/ssh-keys', 422, ['message' => 'Unsupported SSH key type.', 'errors' => ['ssh_keys.2' => ['Unsupported SSH key type. Use ed25519, ECDSA or RSA (2048 bits or more).']]]);

        $this->expectExceptionMessage('Unsupported SSH key type');

        $this->module()->tool_action('ssh-keys', 'add', ['public_key' => 'ssh-dss AAAAB3 old@example.com']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(array $data): string
    {
        $render = static function (array $vars): string {
            extract($vars);
            ob_start();
            include PROXMOTION_WISECP_MODULE . '/pages/tools/ssh-keys.php';

            return (string) ob_get_clean();
        };

        return $render([
            'data' => $data,
            'error' => null,
            'tool_label' => 'SSH keys',
            'lang' => include PROXMOTION_WISECP_MODULE . '/lang/en.php',
        ]);
    }
}
