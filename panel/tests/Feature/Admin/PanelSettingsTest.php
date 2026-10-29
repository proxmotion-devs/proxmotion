<?php

namespace Tests\Feature\Admin;

use App\Domain\Settings\PanelSettings;
use App\Models\AuditLog;
use App\Provisioning\Moves\ServerMover;
use Illuminate\Testing\TestResponse;
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
            'backup_deadline_minutes' => 240,
            'verify_dns_name' => 'Example.org',
            'protected_networks' => "198.51.100.0/24\n203.0.113.7, 198.51.100.0/24",
            'drift_stopped_grace_minutes' => 30,
            'storage_warning_percent' => 80,
            'storage_critical_percent' => 90,
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
            'password_length' => 16,
            'password_characters' => 'lower',
            'password_prefix' => null,
            'password_suffix' => '.example!',
            'ssh_keys_max' => 30,
            'notifications_enabled' => true,
            'notification_recipients' => 'ops@example.com',
            'notification_throttle_minutes' => 30,
            ...$overrides,
        ];
    }

    /**
     * Save one page of Panel settings with its settings from valid().
     */
    private function saveCategory(string $category, array $overrides = []): TestResponse
    {
        $values = array_intersect_key($this->valid($overrides), array_flip(PanelSettings::CATEGORIES[$category]));

        return $this->put(route('admin.settings.update', $category), $values);
    }

    public function test_every_setting_is_on_exactly_one_page()
    {
        $listed = array_merge(...array_values(PanelSettings::CATEGORIES));

        $this->assertSame(count($listed), count(array_unique($listed)));
        $this->assertEqualsCanonicalizing(array_keys(PanelSettings::KEYS), $listed);
    }

    public function test_it_opens_the_first_page()
    {
        $this->get(route('admin.settings.index'))
            ->assertRedirect(route('admin.settings.edit', 'servers'));
    }

    public function test_it_shows_the_values_from_env_until_saved()
    {
        config(['proxmotion.moves.cross_cluster' => false, 'proxmotion.provisioning.min_vmid' => 300]);

        $this->get(route('admin.settings.edit', 'servers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settings/Edit')
                ->where('category', 'servers')
                ->where('fields', PanelSettings::CATEGORIES['servers'])
                ->where('settings.cross_cluster_moves', false)
                ->where('settings.min_vmid', 300)
                ->where('unsaved', PanelSettings::CATEGORIES['servers'])
                ->where('environment.require_admin_two_factor', true));
    }

    public function test_it_refuses_unknown_pages_and_saving_the_env_page()
    {
        $this->get('/admin/settings/nope')->assertNotFound();
        $this->get(route('admin.settings.edit', 'environment'))->assertOk();
        $this->put(route('admin.settings.update', 'environment'), [])->assertMethodNotAllowed();
    }

    public function test_saved_values_win_over_env_and_are_audited()
    {
        config(['proxmotion.moves.cross_cluster' => false]);

        foreach (array_keys(array_filter(PanelSettings::CATEGORIES)) as $category) {
            $this->saveCategory($category)->assertRedirect()->assertSessionHasNoErrors();
        }

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
        $this->assertSame(16, PanelSettings::get('password_length'));
        $this->assertSame('lower', PanelSettings::get('password_characters'));
        $this->assertSame('.example!', PanelSettings::get('password_suffix'));
        $this->assertSame(30, PanelSettings::get('ssh_keys_max'));
        $this->assertSame(80, PanelSettings::get('storage_warning_percent'));
        $this->assertSame(90, PanelSettings::get('storage_critical_percent'));
        $this->assertSame([], PanelSettings::unsaved());

        // .env no longer decides once the page was saved.
        config(['proxmotion.moves.cross_cluster' => false]);
        $this->assertTrue(ServerMover::crossClusterEnabled());

        $entries = AuditLog::query()->where('action', 'settings.updated')->orderBy('id')->get();
        $this->assertCount(5, $entries);
        $this->assertSame(['from' => false, 'to' => true], $entries->first()->properties['changes']['cross_cluster_moves']);
    }

    public function test_a_page_saves_only_its_own_settings()
    {
        config(['proxmotion.provisioning.min_vmid' => 300]);

        // Settings of other pages sent along are ignored.
        $this->put(route('admin.settings.update', 'usage'), [
            ...array_intersect_key($this->valid(), array_flip(PanelSettings::CATEGORIES['usage'])),
            'min_vmid' => 9000,
        ])->assertSessionHasNoErrors();

        $this->assertSame(15, PanelSettings::get('traffic_reset_day'));
        $this->assertSame(300, PanelSettings::get('min_vmid'));
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys(PanelSettings::KEYS), PanelSettings::CATEGORIES['usage'])),
            PanelSettings::unsaved(),
        );

        $entry = AuditLog::query()->where('action', 'settings.updated')->sole();
        $this->assertArrayNotHasKey('min_vmid', $entry->properties['changes']);
    }

    public function test_empty_optional_fields_are_saved_as_none()
    {
        $empty = [
            'move_bwlimit_kib' => null,
            'verify_dns_name' => '',
            'protected_networks' => '',
            'docs_url' => '',
        ];

        foreach (['servers', 'network', 'general'] as $category) {
            $this->saveCategory($category, $empty)->assertSessionHasNoErrors();
        }

        $this->assertNull(PanelSettings::get('move_bwlimit_kib'));
        $this->assertNull(PanelSettings::get('verify_dns_name'));
        $this->assertSame([], PanelSettings::get('protected_networks'));
        $this->assertNull(PanelSettings::get('docs_url'));
    }

    public function test_saving_the_same_values_again_is_not_audited()
    {
        $this->saveCategory('servers');
        $this->saveCategory('servers');

        $this->assertSame(1, AuditLog::query()->where('action', 'settings.updated')->count());
    }

    public function test_it_refuses_invalid_values()
    {
        $invalid = [
            'min_vmid' => 50,
            'move_deadline_minutes' => 5,
            'backup_deadline_minutes' => 5,
            'verify_dns_name' => 'not a name',
            'protected_networks' => "198.51.100.0/24\nnope",
            'docs_url' => 'http://docs.example.com',
            'traffic_reset_day' => 31,
            'traffic_action' => 'stop',
            'usage_cpu_percent' => 150,
            'usage_window_minutes' => 5,
            // Critical must be above the warning.
            'storage_warning_percent' => 90,
            'storage_critical_percent' => 90,
        ];

        $this->saveCategory('servers', $invalid)->assertSessionHasErrors(['min_vmid', 'move_deadline_minutes', 'backup_deadline_minutes', 'verify_dns_name']);
        $this->saveCategory('network', $invalid)->assertSessionHasErrors(['protected_networks']);
        $this->saveCategory('general', $invalid)->assertSessionHasErrors(['docs_url', 'storage_critical_percent']);
        $this->saveCategory('usage', $invalid)->assertSessionHasErrors(['traffic_reset_day', 'traffic_action', 'usage_cpu_percent', 'usage_window_minutes']);

        $this->assertSame(array_keys(PanelSettings::KEYS), PanelSettings::unsaved());
    }

    public function test_a_generated_password_format_needs_three_kinds_of_characters()
    {
        // Letters and digits only: Windows would refuse such passwords.
        $this->saveCategory('servers', ['password_suffix' => null])
            ->assertSessionHasErrors(['password_characters']);

        // A symbol in the suffix makes three kinds.
        $this->saveCategory('servers', ['password_suffix' => '.example!'])
            ->assertSessionHasNoErrors();
    }

    public function test_it_refuses_invalid_password_formats()
    {
        $this->saveCategory('servers', [
            'password_length' => 8,
            'password_characters' => 'digits',
            'password_prefix' => 'a b',
            'password_suffix' => str_repeat('x', 33),
        ])->assertSessionHasErrors(['password_length', 'password_characters', 'password_prefix', 'password_suffix']);

        $this->assertSame(array_keys(PanelSettings::KEYS), PanelSettings::unsaved());
    }

    public function test_guests_cannot_open_or_save_it()
    {
        auth()->logout();

        $this->get(route('admin.settings.edit', 'servers'))->assertRedirect(route('login'));
        $this->saveCategory('servers')->assertRedirect(route('login'));
        $this->assertSame(array_keys(PanelSettings::KEYS), PanelSettings::unsaved());
    }
}
