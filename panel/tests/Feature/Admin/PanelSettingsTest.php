<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\PanelSettings;
use App\Models\AuditLog;
use App\Provisioning\Moves\ServerMover;
use Inertia\Testing\AssertableInertia as Assert;

class PanelSettingsTest extends AdminTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return [
            'cross_cluster_moves' => true,
            'move_deadline_minutes' => 600,
            'move_bwlimit_kib' => 51200,
            'min_vmid' => 5000,
            'provisioning_deadline_minutes' => 90,
            'verify_dns_name' => 'Example.org',
            'protected_networks' => "198.51.100.0/24\n203.0.113.7, 198.51.100.0/24",
            'drift_stopped_grace_minutes' => 30,
            'console_max_minutes' => 45,
            'console_idle_minutes' => 10,
            'docs_url' => 'https://docs.example.com/proxmotion',
            'traffic_reset_day' => 15,
            'traffic_counted' => 'out',
            'traffic_action' => 'suspend',
            'usage_cpu_percent' => 95,
            'usage_memory_percent' => null,
            'usage_disk_mb_s' => 200,
            'usage_network_mbps' => 800,
            'usage_window_minutes' => 120,
            'usage_action' => 'stop',
            ...$overrides,
        ];
    }

    public function test_it_shows_the_values_from_env_until_saved()
    {
        config(['proxmotion.moves.cross_cluster' => false, 'proxmotion.provisioning.min_vmid' => 300]);

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settings/Edit')
                ->where('settings.cross_cluster_moves', false)
                ->where('settings.min_vmid', 300)
                ->where('unsaved', array_keys(PanelSettings::KEYS))
                ->where('environment.require_admin_two_factor', true));
    }

    public function test_saved_values_win_over_env_and_are_audited()
    {
        config(['proxmotion.moves.cross_cluster' => false]);

        $this->put(route('admin.settings.update'), $this->valid())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(ServerMover::crossClusterEnabled());
        $this->assertSame(5000, PanelSettings::get('min_vmid'));
        $this->assertSame(51200, PanelSettings::get('move_bwlimit_kib'));
        $this->assertSame('example.org', PanelSettings::get('verify_dns_name'));
        $this->assertSame(['198.51.100.0/24', '203.0.113.7'], PanelSettings::get('protected_networks'));
        $this->assertSame(15, PanelSettings::get('traffic_reset_day'));
        $this->assertSame('suspend', PanelSettings::get('traffic_action'));
        $this->assertSame(95, PanelSettings::get('usage_cpu_percent'));
        $this->assertNull(PanelSettings::get('usage_memory_percent'));
        $this->assertSame('stop', PanelSettings::get('usage_action'));
        $this->assertSame([], PanelSettings::unsaved());

        // .env no longer decides once the page was saved.
        config(['proxmotion.moves.cross_cluster' => false]);
        $this->assertTrue(ServerMover::crossClusterEnabled());

        $entry = AuditLog::query()->where('action', 'settings.updated')->sole();
        $this->assertSame(['from' => false, 'to' => true], $entry->properties['changes']['cross_cluster_moves']);
    }

    public function test_empty_optional_fields_are_saved_as_none()
    {
        $this->put(route('admin.settings.update'), $this->valid([
            'move_bwlimit_kib' => null,
            'verify_dns_name' => '',
            'protected_networks' => '',
            'docs_url' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertNull(PanelSettings::get('move_bwlimit_kib'));
        $this->assertNull(PanelSettings::get('verify_dns_name'));
        $this->assertSame([], PanelSettings::get('protected_networks'));
        $this->assertNull(PanelSettings::get('docs_url'));
    }

    public function test_saving_the_same_values_again_is_not_audited()
    {
        $this->put(route('admin.settings.update'), $this->valid());
        $this->put(route('admin.settings.update'), $this->valid());

        $this->assertSame(1, AuditLog::query()->where('action', 'settings.updated')->count());
    }

    public function test_it_refuses_invalid_values()
    {
        $this->put(route('admin.settings.update'), $this->valid([
            'min_vmid' => 50,
            'move_deadline_minutes' => 5,
            'verify_dns_name' => 'not a name',
            'protected_networks' => "198.51.100.0/24\nnope",
            'docs_url' => 'http://docs.example.com',
            'traffic_reset_day' => 31,
            'traffic_action' => 'stop',
            'usage_cpu_percent' => 150,
            'usage_window_minutes' => 5,
        ]))->assertSessionHasErrors(['min_vmid', 'move_deadline_minutes', 'verify_dns_name', 'protected_networks', 'docs_url', 'traffic_reset_day', 'traffic_action', 'usage_cpu_percent', 'usage_window_minutes']);

        $this->assertSame(array_keys(PanelSettings::KEYS), PanelSettings::unsaved());
    }

    public function test_guests_cannot_open_or_save_it()
    {
        auth()->logout();

        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'), $this->valid())->assertRedirect(route('login'));
        $this->assertSame(array_keys(PanelSettings::KEYS), PanelSettings::unsaved());
    }
}
