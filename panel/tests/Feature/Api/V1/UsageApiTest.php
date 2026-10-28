<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ServerStatus;
use App\Enums\SuspensionCause;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageRollup;
use App\Models\UsageSample;
use App\Models\UsageViolation;
use Illuminate\Support\Carbon;

/**
 * Traffic and automatic suspensions as billing systems see them (docs/adr/0039).
 */
class UsageApiTest extends ApiTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->server = $this->provisionedServer();
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/servers/{$this->server->uuid}{$suffix}";
    }

    public function test_a_server_tells_its_traffic_in_the_current_period()
    {
        TrafficPeriod::query()->insert([
            ['server_id' => $this->server->id, 'period_start' => '2026-09-01', 'in_bytes' => 999, 'out_bytes' => 999, 'created_at' => now(), 'updated_at' => now()],
            ['server_id' => $this->server->id, 'period_start' => '2026-10-01', 'in_bytes' => 3000, 'out_bytes' => 2000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->api('GET', $this->url())
            ->assertOk()
            ->assertJsonPath('data.suspension_cause', null)
            ->assertJsonPath('data.traffic.period_start', '2026-10-01T00:00:00+00:00')
            ->assertJsonPath('data.traffic.period_end', '2026-11-01T00:00:00+00:00')
            ->assertJsonPath('data.traffic.in_bytes', 3000)
            ->assertJsonPath('data.traffic.out_bytes', 2000)
            ->assertJsonPath('data.traffic.counted_bytes', 5000);

        $this->api('GET', '/api/v1/servers')->assertOk()->assertJsonPath('data.0.traffic.counted_bytes', 5000);
    }

    public function test_a_suspension_by_the_panel_is_taken_over_by_the_billing_system()
    {
        $this->server->forceFill([
            'status' => ServerStatus::Suspended,
            'suspension_cause' => SuspensionCause::Traffic,
            'suspension_reason' => 'Traffic quota exceeded: 11 GB of 10 GB.',
        ])->save();

        $this->api('POST', $this->url('/suspend'), ['reason' => 'Overdue invoice #1234'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_cause', null)
            ->assertJsonPath('data.suspension_reason', 'Overdue invoice #1234');

        $this->assertNull($this->server->refresh()->suspension_cause);
        $this->assertSame(0, $this->server->tasks()->where('type', 'suspend')->count());
        $log = AuditLog::query()->where('action', 'server.suspended')->sole();
        $this->assertSame($this->token->id, $log->api_token_id);
        $this->assertTrue($log->properties['taken_over'] ?? false);

        // Back within its quota, it is no longer the panel's to unsuspend.
        $this->artisan('usage:collect')->assertSuccessful();
        $this->assertSame(ServerStatus::Suspended, $this->server->refresh()->status);
    }

    public function test_a_server_suspended_by_the_billing_system_is_still_a_conflict()
    {
        $this->server->forceFill(['status' => ServerStatus::Suspended])->save();

        $this->api('POST', $this->url('/suspend'))->assertConflict();
    }

    public function test_a_suspension_over_a_usage_limit_keeps_its_reason_when_none_is_given()
    {
        UsageViolation::query()->delete();
        $this->server->forceFill([
            'status' => ServerStatus::Suspended,
            'suspension_cause' => SuspensionCause::Usage,
            'suspension_reason' => 'Usage limit exceeded: CPU averaged 99% over 60 minutes (limit 90%).',
        ])->save();

        $this->api('POST', $this->url('/suspend'))
            ->assertOk()
            ->assertJsonPath('data.suspension_reason', 'Usage limit exceeded: CPU averaged 99% over 60 minutes (limit 90%).');
    }

    public function test_the_usage_history_of_a_range()
    {
        UsageSample::query()->insert([
            'server_id' => $this->server->id,
            'sampled_at' => now()->subMinutes(5),
            'status' => 'running',
            'cpu_percent' => 12.5,
            'net_in_bytes' => 3000,
            'net_out_bytes' => 1000,
        ]);
        UsageRollup::query()->insert([
            'server_id' => $this->server->id,
            'resolution' => UsageRollup::HOUR,
            'starts_at' => '2026-10-15 11:00:00',
            'samples' => 1,
            'cpu_percent' => 12.5,
            'in_bytes' => 3000,
            'out_bytes' => 1000,
        ]);

        $this->api('GET', $this->url('/usage'))
            ->assertOk()
            ->assertJsonPath('data.range', 'day')
            ->assertJsonPath('data.interval_seconds', 300)
            ->assertJsonPath('data.points.0.time', '2026-10-15T11:55:00+00:00')
            ->assertJsonPath('data.points.0.cpu_percent', 12.5)
            ->assertJsonPath('data.points.0.in_bytes', 3000)
            ->assertJsonPath('data.traffic.interval', 'hour')
            ->assertJsonPath('data.traffic.buckets.0.counted_bytes', 4000)
            ->assertJsonPath('data.current.period_start', '2026-10-01T00:00:00+00:00');

        $this->api('GET', $this->url('/usage?range=month'))
            ->assertOk()
            ->assertJsonPath('data.interval_seconds', 3600)
            ->assertJsonPath('data.points.0.time', '2026-10-15T11:00:00+00:00');

        $this->api('GET', $this->url('/usage?range=decade'))->assertUnprocessable()->assertJsonValidationErrors(['range']);
    }
}
