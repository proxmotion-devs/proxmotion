<?php

namespace Tests\Feature\Servers;

use App\Enums\NetworkModel;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * The "Change resources" dialog on the admin server page.
 */
class ChangeResourcesTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Server $server;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        (new FakeProxmoxCluster)->addTemplate(9000)->install();
        $this->server = app(ServerCreator::class)->create($this->order())->refresh();
        $this->admin = User::factory()->withTwoFactor()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'package' => $this->package->uuid,
            'cpu_cores' => 4,
            'memory_mib' => 8192,
            'disk_gib' => 60,
            'ipv4_count' => 1,
            'ipv6_count' => 0,
            'port_speed_mbps' => 1000,
            'traffic_gb' => null,
            'reboot' => false,
            ...$overrides,
        ];
    }

    public function test_the_dialog_gets_the_packages_and_current_resources()
    {
        Package::factory()->create(['name' => 'Routed only', 'network_models' => [NetworkModel::Routed]]);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page
                ->where('server.reboot_required', false)
                ->has('resourceOptions.packages', 1, fn (Assert $package) => $package
                    ->where('value', $this->package->uuid)
                    ->where('disk_gib', 40)
                    ->etc())
                ->where('resourceOptions.current', [
                    'package' => $this->package->uuid,
                    'cpu_cores' => 2,
                    'memory_mib' => 4096,
                    'disk_gib' => 40,
                    'ipv4_count' => 1,
                    'ipv6_count' => 0,
                    'port_speed_mbps' => 1000,
                    'traffic_gb' => $this->package->traffic_bytes === null ? null : intdiv($this->package->traffic_bytes, 1000 ** 3),
                    'cpu_limit' => null,
                    'cpu_units' => null,
                    'disk_mb_s' => null,
                    'disk_iops' => null,
                ]));
    }

    public function test_admins_change_the_resources()
    {
        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.resources', $this->server), $this->payload(['package' => '']))
            ->assertRedirect(route('admin.servers.show', $this->server))
            ->assertSessionHasNoErrors();

        $server = $this->server->refresh();
        $this->assertNull($server->package_id, 'Custom resources.');
        $this->assertSame([4, 8192, 61440], [$server->cpu_cores, $server->memory_mib, $server->disk_mib]);
        $this->assertTrue($server->reboot_required);
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', 'server.resources_changed')->sole()->user_id);

        $this->actingAs($this->admin)
            ->get(route('admin.servers.show', $this->server))
            ->assertInertia(fn (Assert $page) => $page->where('server.reboot_required', true));
    }

    public function test_the_form_is_validated_and_problems_are_shown()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.servers.resources', $this->server), $this->payload(['cpu_cores' => 0, 'disk_gib' => null]))
            ->assertSessionHasErrors(['cpu_cores', 'disk_gib']);

        $this->node->forceFill(['memory_bytes' => 6 * 1024 ** 3, 'memory_overcommit' => 1])->save();

        $this->actingAs($this->admin)
            ->from(route('admin.servers.show', $this->server))
            ->post(route('admin.servers.resources', $this->server), $this->payload())
            ->assertRedirect(route('admin.servers.show', $this->server));

        $this->assertSame(2, $this->server->refresh()->cpu_cores);
    }
}
