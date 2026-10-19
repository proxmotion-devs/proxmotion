<?php

namespace Tests\Feature\Admin;

use App\Domain\Infrastructure\Distributions;
use App\Enums\OsFamily;
use App\Models\Distribution;
use App\Models\OsTemplate;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Administrators manage the operating systems and versions the OS template
 * form offers (docs/adr/0025).
 */
class DistributionManagementTest extends AdminTestCase
{
    public function test_a_system_and_its_versions_can_be_added()
    {
        $this->post(route('admin.distributions.store'), [
            'slug' => ' Zorin ',
            'name' => 'Zorin OS',
            'family' => 'debian',
            'base' => 'ubuntu',
        ])->assertSessionHasNoErrors();

        $zorin = Distribution::query()->where('slug', 'zorin')->sole();
        $this->assertSame([OsFamily::Debian, 'ubuntu', false], [$zorin->family, $zorin->base, $zorin->same_version]);
        // Recipes for the Debian family now cover it; an unlisted system is in no family.
        $this->assertSame(OsFamily::Debian, Distributions::recipeFamily('zorin'));
        $this->assertNull(Distributions::recipeFamily('nobara'));

        // Its own version numbers: each version names the Ubuntu release it is matched as.
        $this->post(route('admin.distributions.releases.store', 'zorin'), ['version' => '17'])->assertSessionHasErrors('base_version');
        $this->post(route('admin.distributions.releases.store', 'zorin'), ['version' => '17', 'base_version' => '19.10'])->assertSessionHasErrors('base_version');
        $this->post(route('admin.distributions.releases.store', 'zorin'), ['version' => '17', 'base_version' => '22.04'])->assertSessionHasNoErrors();
        $this->post(route('admin.distributions.releases.store', 'zorin'), ['version' => '17', 'base_version' => '22.04'])->assertSessionHasErrors('version');

        $this->post(route('admin.os-templates.store'), [
            'name' => 'Zorin OS 17.2',
            'distribution' => 'zorin',
            'version' => '17.2',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $os = OsTemplate::query()->sole();
        $this->assertSame([OsFamily::Debian, 'ubuntu', '22.04'], [$os->family, $os->compatible_with, $os->compatible_version]);

        $this->get(route('admin.os-templates.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('distributions.15.id', 'zorin')
                ->where('distributions.15.releases.0.version', '17')
                ->where('distributions.15.releases.0.base_version', '22.04'));
    }

    public function test_a_version_of_a_base_system_names_nothing_else()
    {
        $this->post(route('admin.distributions.releases.store', 'ubuntu'), ['version' => '24.04'])->assertSessionHasErrors('version');
        $this->post(route('admin.distributions.releases.store', 'ubuntu'), ['version' => '28.04', 'base_version' => '12'])->assertSessionHasErrors('base_version');
        $this->post(route('admin.distributions.releases.store', 'ubuntu'), ['version' => '28.04'])->assertSessionHasNoErrors();

        $release = Distribution::query()->where('slug', 'ubuntu')->sole()->releases()->where('version', '28.04')->sole();
        $this->delete(route('admin.distributions.releases.destroy', ['distribution' => 'ubuntu', 'release' => $release->id]))->assertRedirect();
        $this->assertModelMissing($release);

        // A release of another system is not found under this one.
        $debian = Distribution::query()->where('slug', 'debian')->sole()->releases()->firstOrFail();
        $this->delete(route('admin.distributions.releases.destroy', ['distribution' => 'ubuntu', 'release' => $debian->id]))->assertNotFound();
    }

    public function test_the_built_on_system_must_be_a_base_of_the_same_family()
    {
        $post = fn (array $attributes) => $this->post(route('admin.distributions.store'), $attributes + ['slug' => 'nobara', 'name' => 'Nobara', 'family' => 'rhel']);

        $post(['base' => 'debian'])->assertSessionHasErrors('base');
        $post(['base' => 'pardus', 'family' => 'debian'])->assertSessionHasErrors('base');
        $post(['family' => 'beos'])->assertSessionHasErrors('family');
        $post(['slug' => 'ubuntu'])->assertSessionHasErrors('slug');
        $post(['slug' => '9os'])->assertSessionHasErrors('slug');
        $post(['base' => 'fedora'])->assertSessionHasNoErrors();
    }

    public function test_changing_a_system_updates_its_templates()
    {
        $os = OsTemplate::factory()->create(['distribution' => 'pardus', 'version' => '23.4', 'compatible_with' => 'debian', 'compatible_version' => '12']);
        $this->assertSame(OsFamily::Debian, $os->family);

        $pardus = Distribution::query()->where('slug', 'pardus')->sole();
        $pardus->releases()->where('version', '23')->sole()->update(['base_version' => '13']);

        $this->put(route('admin.distributions.update', 'pardus'), [
            'name' => 'Pardus GNU/Linux',
            'family' => 'debian',
            'base' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Pardus GNU/Linux', $pardus->refresh()->name);
        $this->assertNull($os->refresh()->compatible_with);
        $this->assertNull($os->compatible_version);

        // The ID stays: templates refer to it.
        $this->put(route('admin.distributions.update', 'pardus'), ['slug' => 'pardus2', 'name' => 'Pardus', 'family' => 'debian'])
            ->assertSessionHasErrors('slug');
    }

    public function test_a_system_in_use_cannot_be_deleted()
    {
        OsTemplate::factory()->create(['distribution' => 'rocky', 'version' => '9']);

        $this->delete(route('admin.distributions.destroy', 'rocky'))->assertSessionHas('inertia.flash_data.toast.type', 'error');
        // Pardus is built on Debian.
        $this->delete(route('admin.distributions.destroy', 'debian'))->assertSessionHas('inertia.flash_data.toast.type', 'error');
        $this->assertSame(2, Distribution::query()->whereIn('slug', ['rocky', 'debian'])->count());

        $this->delete(route('admin.distributions.destroy', 'fedora'))->assertSessionHas('inertia.flash_data.toast.type', 'success');
        $this->assertFalse(Distribution::query()->where('slug', 'fedora')->exists());
        $this->assertSame(0, DB::table('distribution_releases')->whereNotIn('distribution_id', Distribution::query()->pluck('id'))->count());
    }
}
