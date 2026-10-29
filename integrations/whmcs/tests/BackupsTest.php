<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

use Proxmotion\Whmcs\ApiException;

/**
 * Backups in the client area (docs/adr/0051 §4): listed with the slots, and
 * taken, restored and deleted through Proxmotion.
 */
class BackupsTest extends ModuleTestCase
{
    const BACKUP = '0199a000-0000-7000-8000-00000000000b';

    /**
     * The overview of an active server whose backups Proxmotion answers with $backups.
     *
     * @param array<string, mixed> $backups
     */
    private function showsBackups(array $backups, int $status = 200): void
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
        $this->api->on('GET /servers/' . self::SERVER_ID . '/snapshots', 404, ['message' => 'Not found.']);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/backups', $status, $status === 200 ? ['data' => $backups] : ['message' => 'Not found.']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function backup(array $overrides = []): array
    {
        return array_merge([
            'id' => self::BACKUP,
            'kind' => 'manual',
            'label' => 'Before PHP 8.4',
            'size_bytes' => 2500000000,
            'os_template' => 'Ubuntu 24.04',
            'created_at' => '2026-10-29T01:05:00+03:00',
            'restorable' => true,
            'unrestorable_reason' => null,
        ], $overrides);
    }

    public function test_the_overview_lists_the_backups_with_the_slots()
    {
        $this->showsBackups([
            'backups' => [$this->backup(), $this->backup(['id' => 'other', 'label' => null, 'restorable' => false, 'unrestorable_reason' => 'Bu yedek sunucudan farklı bir cluster\'da.'])],
            'slots' => 3,
            'unavailable_reason' => null,
        ]);

        $backups = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx']['backups'];

        $this->assertSame('2 / 3', $backups['count']);
        $this->assertTrue($backups['canTake']);
        $this->assertSame([
            'id' => self::BACKUP,
            'label' => 'Before PHP 8.4',
            'time' => '2026-10-29 01:05',
            'size' => '2.5 GB',
            'system' => 'Ubuntu 24.04',
            'restorable' => true,
            'reason' => '',
        ], $backups['items'][0]);
        $this->assertSame('Etiket yok', $backups['items'][1]['label']);
        $this->assertFalse($backups['items'][1]['restorable']);
        $this->assertSame('Bu yedek sunucudan farklı bir cluster\'da.', $backups['items'][1]['reason']);
    }

    public function test_full_slots_and_unavailable_backups_hide_the_form()
    {
        $this->showsBackups(['backups' => [$this->backup()], 'slots' => 1, 'unavailable_reason' => null]);
        $this->assertFalse($this->module()->clientArea()['templateVariables']['pmx']['backups']['canTake']);

        $this->showsBackups(['backups' => [], 'slots' => 2, 'unavailable_reason' => 'No storage that this server\'s node reaches is marked for backups.']);
        $backups = $this->module()->clientArea()['templateVariables']['pmx']['backups'];
        $this->assertFalse($backups['canTake']);
        $this->assertSame('No storage that this server\'s node reaches is marked for backups.', $backups['unavailable']);
    }

    public function test_the_section_is_hidden_without_slots_or_on_an_older_panel()
    {
        $this->showsBackups(['backups' => [], 'slots' => 0, 'unavailable_reason' => 'Backups are not part of this server\'s plan.']);
        $this->assertNull($this->module()->clientArea()['templateVariables']['pmx']['backups']);

        foreach ([404, 500] as $status) {
            // An older panel, or an error: the rest of the page still shows.
            $this->showsBackups([], $status);
            $vars = $this->module()->clientArea()['templateVariables']['pmx'];
            $this->assertNull($vars['backups']);
            $this->assertNull($vars['error']);
            $this->assertNotNull($vars['server']);
        }
    }

    public function test_backups_are_taken_restored_and_deleted()
    {
        $task = ['data' => ['id' => 'task-uuid', 'status' => 'queued']];
        $this->serviceHas($this->server());
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/backups', 202, $task);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/backups/' . self::BACKUP . '/restore', 202, $task);
        $this->api->on('DELETE /servers/' . self::SERVER_ID . '/backups/' . self::BACKUP, 202, $task);

        $this->assertSame('success', $this->module()->backups(['op' => 'take', 'label' => ' Before PHP 8.4 ']));
        $taken = $this->api->last('POST /servers/' . self::SERVER_ID . '/backups');
        $this->assertSame(['label' => 'Before PHP 8.4'], $taken['body']);
        $this->assertStringStartsWith('whmcs-42-backup-', $taken['headers']['Idempotency-Key']);

        $this->assertSame('success', $this->module()->backups(['op' => 'restore', 'backup' => self::BACKUP]));
        $this->assertStringStartsWith('whmcs-42-backup-restore-', $this->api->last('POST /servers/' . self::SERVER_ID . '/backups/' . self::BACKUP . '/restore')['headers']['Idempotency-Key']);
        $this->assertSame('success', $this->module()->backups(['op' => 'delete', 'backup' => self::BACKUP]));
        $this->assertSame('This backup action is not possible.', $this->module()->backups(['op' => 'restore']));
    }

    public function test_the_panel_s_refusal_is_passed_on()
    {
        $this->serviceHas($this->server());
        $this->api->on('GET /servers?external_id=42', 200, ['data' => [$this->server()]]);
        $this->api->on('POST /servers/' . self::SERVER_ID . '/backups', 422, ['message' => 'No storage that this server\'s node reaches is marked for backups.', 'error' => 'no_backup_storage']);

        try {
            $this->module()->backups(['op' => 'take', 'label' => '']);
            $this->fail('The refusal was not passed on.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('marked for backups', $e->describe());
        }

        $this->assertSame(['label' => null], $this->api->last('POST /servers/' . self::SERVER_ID . '/backups')['body']);
    }
}
