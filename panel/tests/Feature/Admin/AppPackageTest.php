<?php

namespace Tests\Feature\Admin;

use App\Domain\Apps\AppPackages;
use App\Enums\AppEntitlementSource;
use App\Models\AppEntitlement;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

/**
 * Installing apps from .proxapp packages (docs/adr/0042): upload, look at the changes, install; remove.
 */
class AppPackageTest extends AdminTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // A recipes directory whose recipe each test can change before packing it.
        $this->directory = sys_get_temp_dir().'/proxmotion-packages-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/recipes/openvpn'), $this->directory.'/openvpn');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function upload(string $name = 'openvpn.proxapp', ?string $content = null): TestResponse
    {
        $content ??= app(AppPackages::class)->pack($this->directory, 'openvpn');

        return $this->post(route('admin.apps.packages.store'), ['package' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    /**
     * Upload the package and return the address of its preview.
     */
    private function preview(): string
    {
        return (string) $this->upload()->assertRedirect()->assertSessionHasNoErrors()->headers->get('Location');
    }

    private function change(string $file, string $search, string $replace): void
    {
        $path = $this->directory.'/openvpn/'.$file;
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString($search, $content);
        file_put_contents($path, str_replace($search, $replace, $content));
    }

    public function test_a_package_is_previewed_and_then_installed()
    {
        $preview = $this->preview();

        $this->get($preview)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/apps/Package')
            ->where('package.slug', 'openvpn')
            ->where('package.version', '1.3.1')
            ->where('status', AppPackages::NEW)
            ->where('current', null)
            ->where('diff', fn (string $diff) => str_contains($diff, 'recipe.yaml')));

        $this->post($preview)->assertRedirect(route('admin.apps.index'));

        $app = AppRecipe::query()->where('slug', 'openvpn')->sole();
        $this->assertTrue($app->is_active);
        $this->assertSame('1.3.1', $app->current()->version);
        $this->assertTrue(AuditLog::query()->where('action', 'app.package_installed')->where('user_id', $this->admin->id)->exists());
        $this->get($preview)->assertNotFound();
    }

    public function test_a_newer_version_is_an_update_with_its_changes()
    {
        $this->post($this->preview());
        $this->change('recipe.yaml', 'version: 1.3.1', 'version: 1.4.0');
        $this->change('install.sh', 'set -euo pipefail', "set -euo pipefail\necho new-step");
        $preview = $this->preview();

        $this->get($preview)->assertInertia(fn (Assert $page) => $page
            ->where('status', AppPackages::UPDATE)
            ->where('current', '1.3.1')
            ->where('diff', fn (string $diff) => str_contains($diff, '+echo new-step')));

        $this->post($preview)->assertRedirect(route('admin.apps.index'));
        $this->assertSame('1.4.0', AppRecipe::query()->sole()->current()->version);
        $this->assertTrue(AuditLog::query()->where('action', 'app.package_updated')->exists());
    }

    public function test_a_package_installed_already_changes_nothing()
    {
        $this->post($this->preview());
        $preview = $this->preview();

        $this->get($preview)->assertInertia(fn (Assert $page) => $page->where('status', AppPackages::CURRENT)->where('diff', ''));
        $this->post($preview)->assertRedirect(route('admin.apps.index'));
        $this->assertSame(1, AppRecipe::query()->sole()->versions()->count());
    }

    public function test_a_changed_version_in_use_is_refused()
    {
        $this->post($this->preview());
        AppInstallation::factory()->for(AppRecipe::query()->sole(), 'recipe')->create();
        $this->change('install.sh', 'set -euo pipefail', "set -euo pipefail\necho changed");
        $preview = $this->preview();

        $this->get($preview)->assertInertia(fn (Assert $page) => $page->where('status', AppPackages::CONFLICT));
        $this->post($preview)->assertRedirect();
        $this->assertStringNotContainsString('echo changed', AppRecipe::query()->sole()->current()->files['install.sh']);
    }

    public function test_a_custom_app_with_the_slug_is_never_replaced()
    {
        $this->post($this->preview());
        AppRecipe::query()->update(['source' => AppRecipe::SOURCE_CUSTOM]);
        $this->change('recipe.yaml', 'version: 1.3.1', 'version: 1.4.0');
        $preview = $this->preview();

        $this->get($preview)->assertInertia(fn (Assert $page) => $page->where('status', AppPackages::TAKEN));
        $this->post($preview)->assertRedirect();
        $this->assertSame('1.3.1', AppRecipe::query()->sole()->current()->version);
    }

    public function test_only_valid_proxapp_files_are_accepted()
    {
        $this->upload('openvpn.zip')->assertSessionHasErrors('package');
        $this->upload('broken.proxapp', 'not a zip')->assertSessionHasErrors('package');

        $this->change('recipe.yaml', 'timeout_minutes: 15', 'timeout_minutes: 0');
        $path = $this->directory.'/invalid.proxapp';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFile($this->directory.'/openvpn/recipe.yaml', 'openvpn/recipe.yaml');
        $zip->addFile($this->directory.'/openvpn/install.sh', 'openvpn/install.sh');
        $zip->close();

        $this->upload('invalid.proxapp', (string) file_get_contents($path))->assertSessionHasErrors('package');
        $this->assertSame(0, AppRecipe::query()->count());
    }

    public function test_an_upload_is_only_shown_to_the_administrator_who_made_it()
    {
        $preview = $this->preview();

        $this->actingAs(User::factory()->withTwoFactor()->create())->get($preview)->assertNotFound();
    }

    public function test_an_app_is_removed_unless_installations_use_it()
    {
        $this->post($this->preview());
        $app = AppRecipe::query()->sole();
        AppInstallation::factory()->for($app, 'recipe')->create();

        $this->delete(route('admin.apps.packages.remove', $app))->assertRedirect();
        $this->assertTrue(AppRecipe::query()->exists(), 'Installations use it.');

        AppInstallation::query()->delete();
        $this->delete(route('admin.apps.packages.remove', $app))->assertRedirect();
        $this->assertFalse(AppRecipe::query()->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'app.removed')->exists());
    }

    public function test_an_app_is_removed_with_revoked_entitlements_but_not_with_active_ones()
    {
        $this->post($this->preview());
        $app = AppRecipe::query()->sole();
        $entitlement = (new AppEntitlement)->forceFill(['server_id' => Server::factory()->create()->id, 'app_recipe_id' => $app->id, 'source' => AppEntitlementSource::Api]);
        $entitlement->save();

        $this->delete(route('admin.apps.packages.remove', $app))->assertRedirect();
        $this->assertTrue(AppRecipe::query()->exists(), 'A billing system still sells the right to install it.');

        $entitlement->forceFill(['revoked_at' => now()])->save();
        $this->delete(route('admin.apps.packages.remove', $app))->assertRedirect();
        $this->assertFalse(AppRecipe::query()->exists());
        $this->assertFalse(AppEntitlement::query()->exists());
    }

    public function test_an_exported_app_is_a_proxapp_package()
    {
        $this->post($this->preview());

        $this->get(route('admin.apps.export', 'openvpn'))->assertOk()->assertDownload('openvpn-1.3.1.proxapp');
    }
}
