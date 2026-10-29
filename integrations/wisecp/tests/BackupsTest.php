<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Exception;

/**
 * The Backups tool (docs/adr/0051 §4): the page, and taking, restoring and
 * deleting backups through Proxmotion.
 */
final class BackupsTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    private const BACKUP = '0199a000-0000-7000-8000-00000000000b';

    private function hasBackups(int $slots = 2, ?string $unavailable = null): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/backups', 200, ['data' => [
            'backups' => [
                ['id' => self::BACKUP, 'kind' => 'manual', 'label' => 'Before PHP 8.4', 'size_bytes' => 2500000000, 'os_template' => 'Ubuntu 24.04', 'created_at' => '2026-10-29T01:05:00+03:00', 'restorable' => true, 'unrestorable_reason' => null],
                ['id' => 'other', 'kind' => 'manual', 'label' => null, 'size_bytes' => null, 'os_template' => null, 'created_at' => null, 'restorable' => false, 'unrestorable_reason' => 'This backup is on another cluster than the server.'],
            ],
            'slots' => $slots,
            'unavailable_reason' => $unavailable,
        ]]);
        $task = ['data' => ['id' => 'task-uuid', 'status' => 'queued']];
        $this->api->on('POST ' . self::SERVER . '/backups', 202, $task);
        $this->api->on('POST ' . self::SERVER . '/backups/' . self::BACKUP . '/restore', 202, $task);
        $this->api->on('DELETE ' . self::SERVER . '/backups/' . self::BACKUP, 202, $task);
    }

    public function test_the_tool_is_added_and_hidden_unless_the_server_is_active(): void
    {
        $tool = $this->module()->get_tool('backups');

        $this->assertNotNull($tool);
        $this->assertSame(['Backups', 'backups', 'page-loader'], [$tool['label'], $tool['page'], $tool['type']]);

        $suspended = $this->module();
        $suspended->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'suspended']]]);
        $this->assertContains('backups', $suspended->removedTools);
    }

    public function test_the_page_data_lists_the_backups_and_whether_one_more_fits(): void
    {
        $this->hasBackups(slots: 3);

        $data = $this->module()->tool_data('backups', 'index');

        $this->assertSame([
            'id' => self::BACKUP,
            'label' => 'Before PHP 8.4',
            'time' => '2026-10-29 01:05',
            'size' => '2.5 GB',
            'system' => 'Ubuntu 24.04',
            'restorable' => true,
            'reason' => '',
        ], $data['backups'][0]);
        $this->assertFalse($data['backups'][1]['restorable']);
        $this->assertSame('This backup is on another cluster than the server.', $data['backups'][1]['reason']);
        $this->assertSame(3, $data['slots']);
        $this->assertTrue($data['can_take']);
    }

    public function test_full_slots_or_unavailable_backups_cannot_take_more(): void
    {
        $this->hasBackups(slots: 2);
        $this->assertFalse($this->module()->tool_data('backups', 'index')['can_take']);

        $this->api = TestProxmotion::$api = new FakeTransport;
        $this->hasBackups(slots: 5, unavailable: 'No storage that this server\'s node reaches is marked for backups.');
        $data = $this->module()->tool_data('backups', 'index');
        $this->assertFalse($data['can_take']);
        $this->assertSame('No storage that this server\'s node reaches is marked for backups.', $data['unavailable']);
    }

    public function test_the_page_shows_the_backups_and_the_form(): void
    {
        $html = $this->page(['backups' => [
            ['id' => self::BACKUP, 'label' => '<b>mine</b>', 'time' => '2026-10-29 01:05', 'size' => '2.5 GB', 'system' => 'Ubuntu 24.04', 'restorable' => true, 'reason' => ''],
            ['id' => 'other', 'label' => '', 'time' => '', 'size' => '', 'system' => '', 'restorable' => false, 'reason' => 'On another cluster.'],
        ], 'slots' => 3, 'unavailable' => null, 'can_take' => true]);

        $this->assertStringContainsString('&lt;b&gt;mine&lt;/b&gt;', $html);
        $this->assertStringContainsString('2.5 GB', $html);
        $this->assertSame(1, substr_count($html, "pmxBackup(this, 'restore')"), 'No restore for a backup the server cannot reach.');
        $this->assertStringContainsString('On another cluster.', $html);
        $this->assertStringContainsString('name="label"', $html);
        $this->assertStringNotContainsString('name="label"', $this->page(['backups' => [], 'slots' => 1, 'unavailable' => 'None in your plan.', 'can_take' => false]));
    }

    public function test_backups_are_taken_restored_and_deleted(): void
    {
        $this->hasBackups();
        $module = $this->module();

        $this->assertSame('successful', $module->tool_action('backups', 'take', ['label' => ' Before PHP 8.4 '])['status']);
        $this->assertSame(['label' => 'Before PHP 8.4'], $this->api->last('POST ' . self::SERVER . '/backups')['body']);

        $module->tool_action('backups', 'restore', ['backup' => self::BACKUP]);
        $module->tool_action('backups', 'delete', ['backup' => self::BACKUP]);
        $this->assertContains('POST ' . self::SERVER . '/backups/' . self::BACKUP . '/restore', $this->api->sent());
        $this->assertContains('DELETE ' . self::SERVER . '/backups/' . self::BACKUP, $this->api->sent());

        $this->expectExceptionMessage('This backup action is not possible.');
        $module->tool_action('backups', 'restore', []);
    }

    public function test_proxmotions_refusal_reaches_the_customer(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/backups', 409, ['message' => 'This server already has 1 backups, as many as it may have. Delete one first.', 'error' => 'backup_slots_full']);

        try {
            $this->module()->tool_action('backups', 'take', ['label' => '']);
            $this->fail('The refusal was not passed on.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Delete one first.', $e->getMessage());
        }

        $this->assertSame(['label' => null], $this->api->last('POST ' . self::SERVER . '/backups')['body']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(array $data): string
    {
        $render = static function (array $vars): string {
            extract($vars);
            ob_start();
            include PROXMOTION_WISECP_MODULE . '/pages/tools/backups.php';

            return (string) ob_get_clean();
        };

        return $render([
            'data' => $data,
            'error' => null,
            'tool_label' => 'Backups',
            'lang' => include PROXMOTION_WISECP_MODULE . '/lang/en.php',
        ]);
    }
}
