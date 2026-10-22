<?php

namespace Tests\Feature\Usage;

use App\Enums\ServerStatus;
use App\Enums\SuspensionCause;
use App\Enums\UsageAction;
use App\Enums\UsageKind;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\TrafficPeriod;
use App\Models\UsageViolation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * What administrators see of traffic quotas and usage limits (docs/adr/0039).
 */
class UsageAdminTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
        $this->buildInfrastructure();
        (new FakeProxmoxCluster)->addVm(100, 'web1.example.com')->install();
        $this->server = Server::factory()->for($this->node)->create(['vmid' => 100, 'traffic_bytes' => 10 * 1000 ** 3]);
        $this->admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($this->admin);
    }

    private function violation(UsageKind $kind = UsageKind::Traffic): UsageViolation
    {
        $violation = new UsageViolation;
        $violation->forceFill([
            'server_id' => $this->server->id,
            'kind' => $kind,
            'period_start' => '2026-10-01',
            'value' => 11.0 * 1000 ** 3,
            'threshold' => 10.0 * 1000 ** 3,
            'action' => UsageAction::Suspend,
            'acted_at' => now(),
        ])->save();

        return $violation;
    }

    public function test_the_server_page_shows_traffic_violations_and_why_it_was_suspended()
    {
        TrafficPeriod::query()->insert(['server_id' => $this->server->id, 'period_start' => '2026-10-01', 'in_bytes' => 1000, 'out_bytes' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $this->violation();
        $this->server->forceFill([
            'status' => ServerStatus::Suspended,
            'suspension_cause' => SuspensionCause::Traffic,
            'suspension_reason' => 'Traffic quota exceeded: 11 GB of 10 GB.',
        ])->save();

        $this->get(route('admin.servers.show', $this->server))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.suspension_cause', 'traffic')
                ->where('server.suspension_reason', 'Traffic quota exceeded: 11 GB of 10 GB.')
                ->where('server.usage_exempt', false)
                ->where('usage.traffic.counted_bytes', 1500)
                ->where('usage.traffic.quota_bytes', 10 * 1000 ** 3)
                ->where('usage.violations.0.kind', 'traffic')
                ->where('usage.violations.0.label', 'Traffic quota')
                ->where('usage.violations.0.action', 'suspend'));
    }

    public function test_administrators_exempt_a_server_and_it_is_audited()
    {
        $this->from(route('admin.servers.show', $this->server))
            ->put(route('admin.servers.usage-exempt', $this->server), ['usage_exempt' => true])
            ->assertRedirect(route('admin.servers.show', $this->server));

        $this->assertTrue($this->server->refresh()->usage_exempt);
        $entry = AuditLog::query()->where('action', 'server.usage_exempt_changed')->sole();
        $this->assertSame($this->admin->id, $entry->user_id);
        $this->assertTrue($entry->properties['usage_exempt'] ?? null);

        $this->put(route('admin.servers.usage-exempt', $this->server), ['usage_exempt' => false]);
        $this->assertFalse($this->server->refresh()->usage_exempt);
        $this->assertSame(2, AuditLog::query()->where('action', 'server.usage_exempt_changed')->count());
    }

    public function test_the_dashboard_lists_servers_over_their_limits()
    {
        $this->violation();
        $this->violation(UsageKind::Cpu)->forceFill(['resolved_at' => now()])->save();

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('usageViolations', 1)
                ->where('usageViolations.0.server.hostname', $this->server->hostname)
                ->where('usageViolationCount', 1));
    }

    public function test_resolved_violations_are_pruned_after_90_days()
    {
        $this->violation()->forceFill(['resolved_at' => now()])->save();
        $this->violation(UsageKind::Cpu);

        $this->travel(UsageViolation::KEEP_DAYS + 1)->days();
        $this->artisan('model:prune', ['--model' => [UsageViolation::class]])->assertSuccessful();

        $this->assertSame([UsageKind::Cpu], UsageViolation::query()->get()->pluck('kind')->all());
    }
}
