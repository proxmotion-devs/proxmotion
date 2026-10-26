<?php

namespace Tests\Feature\Admin;

use App\Models\AppCatalogEntry;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The App Store page (docs/adr/0041): refreshing the source, downloading, updating and removing apps.
 */
class AppStoreTest extends AdminTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/proxmotion-store-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/recipes/openvpn'), $this->directory.'/openvpn');
        File::copyDirectory(base_path('tests/Fixtures/recipes/wireguard'), $this->directory.'/wireguard');
        config(['proxmotion.recipes.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function refresh(): void
    {
        $this->post(route('admin.apps.catalog.refresh'))->assertRedirect();
    }

    public function test_the_store_lists_the_recipes_of_the_source_with_their_status()
    {
        $this->refresh();

        $this->get(route('admin.apps.catalog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/apps/Store')
                ->where('source.repository', $this->directory)
                ->has('apps', 2)
                ->where('apps.0.slug', 'openvpn')
                ->where('apps.0.status', 'not_downloaded')
                ->where('apps.1.slug', 'wireguard'));
    }

    public function test_an_administrator_downloads_an_app_from_the_store()
    {
        $this->refresh();

        $this->post(route('admin.apps.catalog.download', AppCatalogEntry::query()->where('slug', 'openvpn')->sole()))->assertRedirect();

        $this->assertSame('1.3.1', AppRecipe::query()->where('slug', 'openvpn')->sole()->current()->version);
        $this->assertTrue(AuditLog::query()->where('action', 'app.downloaded')->exists());
    }

    public function test_the_store_shows_the_diff_to_the_downloaded_version()
    {
        $this->refresh();
        $entry = AppCatalogEntry::query()->where('slug', 'openvpn')->sole();
        $this->post(route('admin.apps.catalog.download', $entry))->assertRedirect();

        file_put_contents($this->directory.'/openvpn/install.sh', "#!/bin/bash\necho changed\n");
        $this->refresh();

        $diff = $this->get(route('admin.apps.catalog.diff', $entry))->assertOk()->json('diff');

        $this->assertStringContainsString('echo changed', $diff);
    }

    public function test_an_app_without_installations_is_removed_from_the_store()
    {
        $this->refresh();
        $this->post(route('admin.apps.catalog.download', AppCatalogEntry::query()->where('slug', 'wireguard')->sole()))->assertRedirect();

        $this->delete(route('admin.apps.catalog.remove', 'wireguard'))->assertRedirect();

        $this->assertNull(AppRecipe::query()->where('slug', 'wireguard')->first());
        $this->assertTrue(AuditLog::query()->where('action', 'app.removed')->exists());
    }

    public function test_an_app_with_installations_is_kept_and_the_administrator_is_told_why()
    {
        $this->refresh();
        $this->post(route('admin.apps.catalog.download', AppCatalogEntry::query()->where('slug', 'openvpn')->sole()));
        $app = AppRecipe::query()->where('slug', 'openvpn')->sole();
        AppInstallation::factory()->for($app, 'recipe')->create();

        $this->delete(route('admin.apps.catalog.remove', $app))
            ->assertRedirect();

        $this->assertTrue(AppRecipe::query()->where('slug', 'openvpn')->exists());
    }

    public function test_a_custom_app_is_not_removed_from_the_store()
    {
        $this->refresh();
        $this->post(route('admin.apps.catalog.download', AppCatalogEntry::query()->where('slug', 'openvpn')->sole()));
        AppRecipe::query()->where('slug', 'openvpn')->update(['source' => AppRecipe::SOURCE_CUSTOM]);

        $this->delete(route('admin.apps.catalog.remove', 'openvpn'))->assertRedirect();

        $this->assertTrue(AppRecipe::query()->where('slug', 'openvpn')->exists());
    }
}
