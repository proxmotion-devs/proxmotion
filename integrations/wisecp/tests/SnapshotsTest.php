<?php

declare(strict_types=1);

namespace Proxmotion\Wisecp\Tests;

require_once __DIR__ . '/bootstrap.php';

use Exception;

/**
 * The Snapshots tool (docs/adr/0051): the page, and taking, rolling back
 * and deleting snapshots through Proxmotion.
 */
final class SnapshotsTest extends ModuleTestCase
{
    private const SERVER = '/servers/' . self::SERVER_ID;

    private const SNAPSHOT = '0199a000-0000-7000-8000-00000000000a';

    private function hasSnapshots(int $slots = 2, ?string $unavailable = null): void
    {
        $this->serviceHas($this->server());
        $this->api->on('GET ' . self::SERVER . '/snapshots', 200, ['data' => [
            'snapshots' => [['id' => self::SNAPSHOT, 'label' => 'Before PHP 8.4', 'created_at' => '2026-10-28T14:05:00+03:00']],
            'slots' => $slots,
            'unavailable_reason' => $unavailable,
        ]]);
        $task = ['data' => ['id' => 'task-uuid', 'status' => 'queued']];
        $this->api->on('POST ' . self::SERVER . '/snapshots', 202, $task);
        $this->api->on('POST ' . self::SERVER . '/snapshots/' . self::SNAPSHOT . '/rollback', 202, $task);
        $this->api->on('DELETE ' . self::SERVER . '/snapshots/' . self::SNAPSHOT, 202, $task);
    }

    public function test_the_tool_is_added_and_hidden_unless_the_server_is_active(): void
    {
        $tool = $this->module()->get_tool('snapshots');

        $this->assertNotNull($tool);
        $this->assertSame(['Snapshots', 'snapshots', 'page-loader'], [$tool['label'], $tool['page'], $tool['type']]);

        $suspended = $this->module();
        $suspended->configure_dashboard_tools(['server_info' => ['status' => ['raw' => 'suspended']]]);
        $this->assertContains('snapshots', $suspended->removedTools);
    }

    public function test_the_page_data_lists_the_snapshots_and_whether_one_more_fits(): void
    {
        $this->hasSnapshots();

        $data = $this->module()->tool_data('snapshots', 'index');

        $this->assertSame([['id' => self::SNAPSHOT, 'label' => 'Before PHP 8.4', 'time' => '2026-10-28 14:05']], $data['snapshots']);
        $this->assertSame(2, $data['slots']);
        $this->assertTrue($data['can_take']);
    }

    public function test_full_slots_or_unavailable_snapshots_cannot_take_more(): void
    {
        $this->hasSnapshots(slots: 1);
        $this->assertFalse($this->module()->tool_data('snapshots', 'index')['can_take']);

        $this->api = TestProxmotion::$api = new FakeTransport;
        $this->hasSnapshots(slots: 0, unavailable: 'Snapshots are not part of this server\'s plan.');
        $data = $this->module()->tool_data('snapshots', 'index');
        $this->assertFalse($data['can_take']);
        $this->assertSame('Snapshots are not part of this server\'s plan.', $data['unavailable']);
    }

    public function test_the_page_shows_the_snapshots_and_the_form(): void
    {
        $html = $this->page(['snapshots' => [['id' => self::SNAPSHOT, 'label' => '<b>mine</b>', 'time' => '2026-10-28 14:05']], 'slots' => 2, 'unavailable' => null, 'can_take' => true]);

        $this->assertStringContainsString('&lt;b&gt;mine&lt;/b&gt;', $html);
        $this->assertStringContainsString('data-snapshot="' . self::SNAPSHOT . '"', $html);
        $this->assertStringContainsString('name="label"', $html);
        $this->assertStringNotContainsString('name="label"', $this->page(['snapshots' => [], 'slots' => 1, 'unavailable' => 'None in your plan.', 'can_take' => false]));
        $this->assertStringContainsString('None in your plan.', $this->page(['snapshots' => [], 'slots' => 0, 'unavailable' => 'None in your plan.', 'can_take' => false]));
    }

    public function test_snapshots_are_taken_rolled_back_and_deleted(): void
    {
        $this->hasSnapshots();
        $module = $this->module();

        $this->assertSame('successful', $module->tool_action('snapshots', 'take', ['label' => ' Before PHP 8.4 '])['status']);
        $taken = $this->api->last('POST ' . self::SERVER . '/snapshots');
        $this->assertSame(['label' => 'Before PHP 8.4'], $taken['body']);

        $module->tool_action('snapshots', 'rollback', ['snapshot' => self::SNAPSHOT]);
        $module->tool_action('snapshots', 'delete', ['snapshot' => self::SNAPSHOT]);
        $this->assertContains('POST ' . self::SERVER . '/snapshots/' . self::SNAPSHOT . '/rollback', $this->api->sent());
        $this->assertContains('DELETE ' . self::SERVER . '/snapshots/' . self::SNAPSHOT, $this->api->sent());

        $this->expectExceptionMessage('This snapshot action is not possible.');
        $module->tool_action('snapshots', 'rollback', []);
    }

    public function test_proxmotions_refusal_reaches_the_customer(): void
    {
        $this->serviceHas($this->server());
        $this->api->on('POST ' . self::SERVER . '/snapshots', 409, ['message' => 'This server already has 1 snapshots, as many as it may have. Delete one first.', 'error' => 'snapshot_slots_full']);

        try {
            $this->module()->tool_action('snapshots', 'take', ['label' => '']);
            $this->fail('The refusal was not passed on.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Delete one first.', $e->getMessage());
        }

        $this->assertSame(['label' => null], $this->api->last('POST ' . self::SERVER . '/snapshots')['body']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(array $data): string
    {
        $render = static function (array $vars): string {
            extract($vars);
            ob_start();
            include PROXMOTION_WISECP_MODULE . '/pages/tools/snapshots.php';

            return (string) ob_get_clean();
        };

        return $render([
            'data' => $data,
            'error' => null,
            'tool_label' => 'Snapshots',
            'lang' => include PROXMOTION_WISECP_MODULE . '/lang/en.php',
        ]);
    }
}
