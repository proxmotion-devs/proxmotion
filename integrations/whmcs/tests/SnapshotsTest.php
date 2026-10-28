<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;

/**
 * Snapshots in the client area (docs/adr/0051): listed with the slots, and
 * taken, rolled back and deleted through Proxmotion.
 */
class SnapshotsTest extends ModuleTestCase
{
    const SNAPSHOT = '0199a000-0000-7000-8000-00000000000a';

    /**
     * The overview of an active server whose snapshots Proxmotion answers with $snapshots.
     *
     * @param array<string, mixed> $snapshots
     */
    private function showsSnapshots(array $snapshots, int $status = 200): void
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
        $this->api->on('GET /servers/' . self::SERVER_ID . '/ssh-keys', 200, ['data' => ['ssh_keys' => [], 'max_keys' => 20]]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/usage?range=day', 404, ['message' => 'Not found.']);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/snapshots', $status, $status === 200 ? ['data' => $snapshots] : ['message' => 'Not found.']);
    }

    public function test_the_overview_lists_the_snapshots_with_the_slots()
    {
        $this->showsSnapshots([
            'snapshots' => [['id' => self::SNAPSHOT, 'label' => 'Before PHP 8.4', 'created_at' => '2026-10-28T14:05:00+03:00'], ['id' => 'other', 'label' => null, 'created_at' => '2026-10-28T15:00:00+03:00']],
            'slots' => 3,
            'unavailable_reason' => null,
        ]);

        $snapshots = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx']['snapshots'];

        $this->assertSame('2 / 3', $snapshots['count']);
        $this->assertTrue($snapshots['canTake']);
        $this->assertSame(['id' => self::SNAPSHOT, 'label' => 'Before PHP 8.4', 'time' => '2026-10-28 14:05'], $snapshots['items'][0]);
        $this->assertSame('Etiket yok', $snapshots['items'][1]['label']);
    }

    public function test_full_slots_and_unavailable_snapshots_hide_the_form()
    {
        $this->showsSnapshots(['snapshots' => [['id' => self::SNAPSHOT, 'label' => 'One', 'created_at' => null]], 'slots' => 1, 'unavailable_reason' => null]);
        $this->assertFalse($this->module()->clientArea()['templateVariables']['pmx']['snapshots']['canTake']);

        $this->showsSnapshots(['snapshots' => [], 'slots' => 2, 'unavailable_reason' => 'The storage of this server (lvm) does not support snapshots.']);
        $snapshots = $this->module()->clientArea()['templateVariables']['pmx']['snapshots'];
        $this->assertFalse($snapshots['canTake']);
        $this->assertSame('The storage of this server (lvm) does not support snapshots.', $snapshots['unavailable']);
    }

    public function test_the_section_is_hidden_without_slots_or_on_an_older_panel()
    {
        $this->showsSnapshots(['snapshots' => [], 'slots' => 0, 'unavailable_reason' => 'Snapshots are not part of this server\'s plan.']);
        $this->assertNull($this->module()->clientArea()['templateVariables']['pmx']['snapshots']);

        foreach ([404, 500] as $status) {
            // An older panel, or an error: the rest of the page still shows.
            $this->showsSnapshots([], $status);
            $vars = $this->module()->clientArea()['templateVariables']['pmx'];
            $this->assertNull($vars['snapshots']);
            $this->assertNull($vars['error']);
            $this->assertNotNull($vars['server']);
        }
    }

    public function test_snapshots_are_taken_rolled_back_and_deleted()
    {
        $task = ['data' => ['id' => 'task-uuid', 'status' => 'queued']];
        $this->serviceHas($this->server());
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/snapshots', 202, $task);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/snapshots/' . self::SNAPSHOT . '/rollback', 202, $task);
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/snapshots/' . self::SNAPSHOT, 202, $task);

        $this->assertSame('success', $this->module()->snapshots(['op' => 'take', 'label' => ' Before PHP 8.4 ']));
        $taken = $this->api->last('POST /servers/' . self::SERVER_ID . '/snapshots');
        $this->assertSame(['label' => 'Before PHP 8.4'], $taken['body']);
        $this->assertStringStartsWith('whmcs-42-snapshot-', $taken['headers']['Idempotency-Key']);

        $this->assertSame('success', $this->module()->snapshots(['op' => 'rollback', 'snapshot' => self::SNAPSHOT]));
        $this->assertSame('success', $this->module()->snapshots(['op' => 'delete', 'snapshot' => self::SNAPSHOT]));
        $this->assertSame('This snapshot action is not possible.', $this->module()->snapshots(['op' => 'rollback']));
    }

    public function test_the_panel_s_refusal_is_passed_on()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/snapshots', 409, ['message' => 'This server already has 1 snapshots, as many as it may have. Delete one first.', 'error' => 'snapshot_slots_full']);

        try {
            $this->module()->snapshots(['op' => 'take', 'label' => '']);
            $this->fail('The refusal was not passed on.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('Delete one first.', $e->describe());
        }

        $this->assertSame(['label' => null], $this->api->last('POST /servers/' . self::SERVER_ID . '/snapshots')['body']);
    }
}
