<?php

namespace Tests\Feature\Admin;

use App\Enums\OsPlatform;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\OperatingSystem;
use App\Models\OsTemplate;

/**
 * Administrators keep their own list of operating systems and versions,
 * and tick the versions each app is offered on (docs/adr/0026).
 */
class OperatingSystemManagementTest extends AdminTestCase
{
    public function test_a_system_and_its_versions_are_added_by_name()
    {
        $this->post(route('admin.operating-systems.store'), ['name' => ' Ubuntu ', 'platform' => 'linux'])->assertSessionHasNoErrors();
        $this->post(route('admin.operating-systems.store'), ['name' => 'Ubuntu', 'platform' => 'linux'])->assertSessionHasErrors('name');
        $this->post(route('admin.operating-systems.store'), ['name' => 'Rocky Linux', 'platform' => 'beos'])->assertSessionHasErrors('platform');

        $ubuntu = OperatingSystem::query()->sole();
        $this->assertSame(['Ubuntu', 'ubuntu', OsPlatform::Linux], [$ubuntu->name, $ubuntu->slug, $ubuntu->platform]);

        $this->post(route('admin.operating-systems.versions.store', $ubuntu), ['version' => '26.04'])->assertSessionHasNoErrors();
        $this->post(route('admin.operating-systems.versions.store', $ubuntu), ['version' => '26.04'])->assertSessionHasErrors('version');
        $this->post(route('admin.operating-systems.versions.store', $ubuntu), ['version' => ''])->assertSessionHasErrors('version');
        $this->assertSame(['26.04'], $ubuntu->versions()->pluck('version')->all());
    }

    public function test_systems_and_versions_in_use_stay()
    {
        $template = OsTemplate::factory()->create(['distribution' => 'ubuntu', 'version' => '24.04']);
        $ubuntu = OperatingSystem::query()->where('slug', 'ubuntu')->sole();
        $noble = $ubuntu->versions()->sole();
        $old = $ubuntu->versions()->create(['version' => '20.04']);

        $this->delete(route('admin.operating-systems.versions.destroy', [$ubuntu, $noble]))->assertSessionHas('inertia.flash_data.toast.type', 'error');
        $this->delete(route('admin.operating-systems.destroy', $ubuntu))->assertSessionHas('inertia.flash_data.toast.type', 'error');
        $this->put(route('admin.operating-systems.update', $ubuntu), ['name' => 'Ubuntu', 'platform' => 'windows'])->assertSessionHasErrors('platform');

        $this->delete(route('admin.operating-systems.versions.destroy', [$ubuntu, $old]))->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $this->assertModelMissing($old);

        // A version of another system is not found under this one.
        $other = OperatingSystem::query()->create(['name' => 'Debian', 'platform' => 'linux'])->versions()->create(['version' => '12']);
        $this->delete(route('admin.operating-systems.versions.destroy', [$ubuntu, $other]))->assertNotFound();

        // Renaming keeps the slug templates copied.
        $this->put(route('admin.operating-systems.update', $ubuntu), ['name' => 'Ubuntu Server', 'platform' => 'linux'])->assertSessionHasNoErrors();
        $this->assertSame(['Ubuntu Server', 'ubuntu'], [$ubuntu->refresh()->name, $template->refresh()->distribution]);

        $template->delete();
        $this->delete(route('admin.operating-systems.destroy', $ubuntu))->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $this->assertSame(0, OperatingSystem::query()->where('slug', 'ubuntu')->count());
    }

    public function test_admins_tick_the_versions_an_app_is_offered_on()
    {
        $this->syncRecipes();
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->sole();
        $ubuntu = OperatingSystem::query()->create(['name' => 'Ubuntu', 'platform' => 'linux']);
        $noble = $ubuntu->versions()->create(['version' => '24.04']);
        $bionic = $ubuntu->versions()->create(['version' => '18.04']);
        $windows = OperatingSystem::query()->create(['name' => 'Windows Server', 'platform' => 'windows'])->versions()->create(['version' => '2022']);

        // The recipe suggests what it says it runs on; nothing is ticked yet.
        $this->get(route('admin.apps.index'))->assertInertia(fn ($page) => $page
            ->where('apps', fn ($apps) => collect($apps)->firstWhere('slug', 'wireguard')['suggested_ids'] === [$noble->id]
                && collect($apps)->firstWhere('slug', 'wireguard')['os_version_ids'] === []));

        $this->put(route('admin.apps.systems', $wireguard), ['os_version_ids' => [$windows->id]])->assertSessionHasErrors('os_version_ids');
        $this->put(route('admin.apps.systems', $wireguard), ['os_version_ids' => [$noble->id, $bionic->id]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$noble->id, $bionic->id], $wireguard->osVersions()->pluck('operating_system_versions.id')->all());
        $this->assertEqualsCanonicalizing(['Ubuntu 24.04', 'Ubuntu 18.04'], AuditLog::query()->where('action', 'app.systems_changed')->sole()->properties['added']);

        $this->put(route('admin.apps.systems', $wireguard), ['os_version_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $wireguard->osVersions()->count());
    }

    public function test_the_page_counts_the_apps_a_deletion_would_untick_and_the_deletion_is_audited()
    {
        $this->syncRecipes();
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->sole();
        $docker = AppRecipe::query()->where('slug', 'docker')->sole();
        $ubuntu = OperatingSystem::query()->create(['name' => 'Ubuntu', 'platform' => 'linux']);
        $noble = $ubuntu->versions()->create(['version' => '24.04']);
        $jammy = $ubuntu->versions()->create(['version' => '22.04']);
        $wireguard->osVersions()->sync([$noble->id, $jammy->id]);
        $docker->osVersions()->sync([$noble->id]);

        // Two apps on 24.04, one on 22.04, two distinct apps on Ubuntu.
        $this->get(route('admin.os-templates.index'))->assertInertia(fn ($page) => $page
            ->where('systems.0.apps', 2)
            ->where('systems.0.versions', fn ($versions) => collect($versions)->pluck('apps', 'version')->all() === ['24.04' => 2, '22.04' => 1]));

        $this->delete(route('admin.operating-systems.versions.destroy', [$ubuntu, $noble]))->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $this->assertSame(['docker', 'wireguard'], AuditLog::query()->where('action', 'operating_system.version_deleted')->sole()->properties['apps']);
        $this->assertSame(0, $docker->osVersions()->count());

        $this->delete(route('admin.operating-systems.destroy', $ubuntu))->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $entry = AuditLog::query()->where('action', 'operating_system.deleted')->sole();
        $this->assertSame(['Ubuntu', ['wireguard']], [$entry->properties['name'], $entry->properties['apps']]);
    }
}
