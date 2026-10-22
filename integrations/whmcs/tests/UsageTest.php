<?php

namespace Proxmotion\Whmcs\Tests;

require_once __DIR__ . '/bootstrap.php';

/**
 * Traffic accounting (docs/adr/0039): UsageUpdate and the month's traffic in
 * the client area and the admin service tab.
 */
class UsageTest extends ModuleTestCase
{
    const GB = 1000000000;

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function trafficServer(int $countedBytes, ?int $quotaBytes, array $overrides = []): array
    {
        return $this->server(array_replace([
            'resources' => ['cpu_cores' => 2, 'memory_mib' => 4096, 'disk_mib' => 40960, 'port_speed_mbps' => 1000, 'traffic_bytes' => $quotaBytes],
            'traffic' => [
                'period_start' => '2026-10-01T00:00:00+03:00',
                'period_end' => '2026-11-01T00:00:00+03:00',
                'in_bytes' => (int) ($countedBytes / 4),
                'out_bytes' => $countedBytes - (int) ($countedBytes / 4),
                'counted_bytes' => $countedBytes,
            ],
            'suspension_cause' => null,
        ], $overrides));
    }

    private function showsServer(array $server): void
    {
        $this->serviceHas($server);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $server]);
        $this->api->on('GET /os-templates', 200, ['data' => []]);
        $this->api->on('GET /servers/' . self::SERVER_ID . '/apps', 200, ['data' => []]);
        $this->api->on('GET /apps?server=' . self::SERVER_ID, 200, ['data' => []]);
        $this->api->on('GET /apps?mode=order&server=' . self::SERVER_ID, 200, ['data' => []]);
    }

    public function test_usage_update_writes_every_service_of_the_whmcs_server_page_by_page()
    {
        $this->api->on('GET /servers?page=1', 200, [
            'data' => [
                $this->trafficServer(12 * self::GB, 100 * self::GB, ['external_id' => '42']),
                $this->trafficServer(3 * self::GB, null, ['id' => 'other', 'external_id' => '43']),
            ],
            'meta' => ['current_page' => 1, 'last_page' => 2],
        ]);
        $this->api->on('GET /servers?page=2', 200, [
            'data' => [
                // On another WHMCS server: not written.
                $this->trafficServer(self::GB, null, ['id' => 'elsewhere', 'external_id' => '44']),
                // The terminated server of service 43 is older history.
                $this->trafficServer(0, 1, ['id' => 'old', 'external_id' => '43', 'status' => 'terminated']),
                // Ordered by another billing system with its own ids.
                $this->trafficServer(self::GB, null, ['id' => 'foreign', 'external_id' => 'order-17']),
                // A panel before traffic accounting.
                $this->server(['id' => 'older', 'external_id' => '45']),
            ],
            'meta' => ['current_page' => 2, 'last_page' => 2],
        ]);
        $store = new FakeUsageStore();
        $store->services = [42 => 3, 43 => 3, 44 => 8, 45 => 3];

        $result = $this->module(['serverid' => 3, 'serviceid' => null])->usageUpdate($store);

        $this->assertSame('success', $result);
        $this->assertSame(['GET /servers?page=1', 'GET /servers?page=2'], $this->api->sent());
        $this->assertSame([
            42 => ['bwusage' => 12000, 'bwlimit' => 100000],
            43 => ['bwusage' => 3000, 'bwlimit' => 0],
        ], $store->written);
    }

    public function test_usage_update_stops_at_the_last_page_and_needs_the_whmcs_server()
    {
        $this->api->on('GET /servers?page=1', 200, ['data' => [$this->trafficServer(1500000, 1, ['external_id' => '42'])]]);
        $store = new FakeUsageStore();
        $store->services = [42 => 3];

        $this->assertSame('success', $this->module(['serverid' => 3])->usageUpdate($store));
        $this->assertSame(['GET /servers?page=1'], $this->api->sent(), 'Without meta the first page is the last.');
        $this->assertSame([42 => ['bwusage' => 2, 'bwlimit' => 1]], $store->written, 'MB are rounded; a quota under 1 MB stays a quota.');

        $this->assertSame('The WHMCS server of the usage update is not known.', $this->module()->usageUpdate(new FakeUsageStore()));
    }

    public function test_usage_update_reports_an_unreachable_panel()
    {
        $this->api->failOn('GET /servers?page=1');

        $result = proxmotion_run($this->params(['serverid' => 3]), function () {
            return $this->module(['serverid' => 3])->usageUpdate(new FakeUsageStore());
        });

        $this->assertStringStartsWith('Could not connect to Proxmotion', $result);
    }

    public function test_the_overview_shows_the_months_traffic_against_the_quota()
    {
        $this->showsServer($this->trafficServer((int) (12.5 * self::GB), 100 * self::GB));

        $traffic = $this->module()->clientArea()['templateVariables']['pmx']['server']['traffic'];

        $this->assertSame('12.5 GB of 100 GB (12%)', $traffic['summary']);
        $this->assertSame('Received 3.1 GB, sent 9.4 GB. Starts again on 2026-11-01.', $traffic['details']);
        $this->assertSame(12, $traffic['percent']);
        $this->assertSame('100 GB', $traffic['quota']);
        $this->assertFalse($traffic['over']);
    }

    public function test_the_overview_shows_traffic_without_a_quota_and_in_the_customers_language()
    {
        $this->showsServer($this->trafficServer(300 * 1000000, null));

        $traffic = $this->module(['clientsdetails' => ['language' => 'turkish']])->clientArea()['templateVariables']['pmx']['server']['traffic'];

        $this->assertSame('300 MB (sınırsız)', $traffic['summary']);
        $this->assertNull($traffic['percent']);
        $this->assertNull($traffic['quota']);
    }

    public function test_the_overview_without_traffic_from_an_older_panel()
    {
        $this->showsServer($this->server());

        $server = $this->module()->clientArea()['templateVariables']['pmx']['server'];

        $this->assertNull($server['traffic']);
        $this->assertNull($server['suspension']);
    }

    public function test_the_overview_says_why_proxmotion_suspended_the_server()
    {
        $this->showsServer($this->trafficServer(101 * self::GB, 100 * self::GB, [
            'status' => 'suspended',
            'power_state' => 'stopped',
            'suspension_cause' => 'traffic',
            'suspension_reason' => 'Traffic quota exceeded: 101 GB of 100 GB.',
        ]));

        $server = $this->module()->clientArea()['templateVariables']['pmx']['server'];

        $this->assertSame('traffic', $server['suspension']['cause']);
        $this->assertStringContainsString('when the next month starts on 2026-11-01', $server['suspension']['message']);
        $this->assertSame('Traffic quota exceeded: 101 GB of 100 GB.', $server['suspension']['reason']);
        $this->assertTrue($server['traffic']['over']);
        $this->assertSame(100, $server['traffic']['percent']);
    }

    public function test_a_suspension_for_usage_asks_to_contact_support_and_others_say_nothing()
    {
        $this->showsServer($this->trafficServer(0, null, ['status' => 'suspended', 'suspension_cause' => 'usage', 'suspension_reason' => 'CPU above 90% for 60 minutes.']));
        $this->assertSame(
            'The server is suspended because it used more resources than allowed for too long. Please contact support.',
            $this->module()->clientArea()['templateVariables']['pmx']['server']['suspension']['message']
        );

        $this->freshApi();
        $this->showsServer($this->trafficServer(0, null, ['status' => 'suspended', 'suspension_reason' => 'Overdue invoice']));
        $this->assertNull($this->module()->clientArea()['templateVariables']['pmx']['server']['suspension'], 'A suspension by WHMCS is not explained again.');
    }

    public function test_the_admin_tab_shows_the_traffic_and_a_suspension_by_proxmotion()
    {
        $server = $this->trafficServer(101 * self::GB, 100 * self::GB, ['status' => 'suspended', 'power_state' => 'stopped', 'suspension_cause' => 'traffic', 'suspension_reason' => 'Traffic quota exceeded: 101 GB of 100 GB.']);
        $this->serviceHas($server);
        $this->api->on('GET /servers/' . self::SERVER_ID, 200, ['data' => $server]);

        $fields = $this->module(['clientsdetails' => ['language' => 'turkish']])->adminServiceFields();

        $this->assertSame('101 GB of 100 GB (100%) — Received 25.3 GB, sent 75.8 GB. Starts again on 2026-11-01.', $fields['Traffic this period']);
        $this->assertSame('suspended (stopped)<br>Suspended by Proxmotion (traffic): Traffic quota exceeded: 101 GB of 100 GB.', $fields['Server status']);
    }

    public function test_usage_update_is_a_whmcs_function()
    {
        $this->assertTrue(function_exists('proxmotion_UsageUpdate'));
    }
}
