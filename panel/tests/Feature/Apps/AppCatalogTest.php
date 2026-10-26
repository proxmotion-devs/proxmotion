<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\Catalog\AppCatalog;
use App\Domain\Apps\Catalog\CatalogRefused;
use App\Domain\Apps\Catalog\RecipeSource;
use App\Models\AppCatalogEntry;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The App Store (docs/adr/0041): refreshing a source into catalog entries, and
 * downloading, updating and removing apps from them.
 */
class AppCatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // A recipes directory with two valid recipes to break in each test.
        $this->directory = sys_get_temp_dir().'/proxmotion-catalog-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/recipes/openvpn'), $this->directory.'/openvpn');
        File::copyDirectory(base_path('tests/Fixtures/recipes/wireguard'), $this->directory.'/wireguard');
        config(['proxmotion.recipes.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function catalog(): AppCatalog
    {
        return app(AppCatalog::class);
    }

    private function entry(string $slug): AppCatalogEntry
    {
        return AppCatalogEntry::query()->where('slug', $slug)->sole();
    }

    private function edit(string $file, string $search, string $replace): void
    {
        $path = $this->directory.'/'.$file;
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString($search, $content);
        file_put_contents($path, str_replace($search, $replace, $content));
    }

    public function test_a_refresh_lists_the_recipes_of_the_source_as_entries()
    {
        $state = $this->catalog()->refresh(RecipeSource::official());

        $this->assertSame(['openvpn', 'wireguard'], AppCatalogEntry::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertSame($this->directory, $state->repository);
        $this->assertNull($state->commit, 'A local directory has no commit.');
        $this->assertNotNull($state->fetched_at);
        $this->assertNull($state->error);
        $this->assertSame('1.3.1', $this->entry('openvpn')->version);
        $this->assertNull($this->entry('openvpn')->errors);
    }

    public function test_an_invalid_recipe_is_listed_with_its_errors_and_the_others_stay_usable()
    {
        $this->edit('openvpn/recipe.yaml', 'timeout_minutes: 15', 'timeout_minutes: 0');

        $this->catalog()->refresh(RecipeSource::official());

        $this->assertNotEmpty($this->entry('openvpn')->errors);
        $this->assertNull($this->entry('wireguard')->errors);
        $this->assertSame(AppCatalog::INVALID, $this->catalog()->status($this->entry('openvpn'), null));
    }

    public function test_an_app_whose_recipe_left_the_source_is_no_longer_offered()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $this->catalog()->download($this->entry('openvpn'), null, null);
        $this->catalog()->download($this->entry('wireguard'), null, null);

        File::deleteDirectory($this->directory.'/openvpn');
        $this->catalog()->refresh(RecipeSource::official());

        $this->assertSame(['wireguard'], AppCatalogEntry::query()->pluck('slug')->all());
        $this->assertFalse(AppRecipe::query()->where('slug', 'openvpn')->sole()->is_active);
        $this->assertTrue(AppRecipe::query()->where('slug', 'wireguard')->sole()->is_active);
    }

    public function test_a_source_that_cannot_be_read_keeps_its_entries_and_says_why()
    {
        $this->catalog()->refresh(RecipeSource::official());

        config(['proxmotion.recipes.path' => $this->directory.'-missing']);
        $state = $this->catalog()->refresh(RecipeSource::official());

        $this->assertStringContainsString('does not exist', (string) $state->error);
        $this->assertSame(['openvpn', 'wireguard'], AppCatalogEntry::query()->orderBy('slug')->pluck('slug')->all());
    }

    public function test_a_refresh_is_refused_while_another_one_runs()
    {
        $lock = Cache::lock('app-catalog:official', 60);
        $this->assertTrue($lock->acquire());

        try {
            $this->assertNull($this->catalog()->refresh(RecipeSource::official()));
        } finally {
            $lock->release();
        }
    }

    public function test_a_download_creates_the_app_and_is_audited()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $admin = User::factory()->create();

        $this->assertSame('created', $this->catalog()->download($this->entry('openvpn'), $admin, '127.0.0.1'));

        $app = AppRecipe::query()->where('slug', 'openvpn')->sole();
        $this->assertTrue($app->is_active);
        $this->assertSame('1.3.1', $app->current()->version);
        $this->assertSame(AppRecipeVersion::ORIGIN_UPSTREAM, $app->current()->origin);
        $this->assertTrue(AuditLog::query()->where('action', 'app.downloaded')->where('user_id', $admin->id)->exists());
        $this->assertNull($this->catalog()->download($this->entry('openvpn'), $admin, null), 'Nothing changed: nothing to download.');
    }

    public function test_an_update_the_administrator_edited_in_place_is_held()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $this->catalog()->download($this->entry('openvpn'), null, null);
        AppRecipe::query()->where('slug', 'openvpn')->sole()->latestVersion->forceFill(['origin' => AppRecipeVersion::ORIGIN_LOCAL])->save();

        $this->edit('openvpn/recipe.yaml', 'version: 1.3.1', 'version: 1.4.0');
        $this->catalog()->refresh(RecipeSource::official());

        $this->assertSame('held', $this->catalog()->download($this->entry('openvpn'), null, null));
        $this->assertSame(AppCatalog::EDITED, $this->catalog()->status($this->entry('openvpn'), AppRecipe::query()->where('slug', 'openvpn')->sole()));
    }

    public function test_the_status_tells_what_the_store_shows()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $entry = $this->entry('openvpn');
        $this->assertSame(AppCatalog::NOT_DOWNLOADED, $this->catalog()->status($entry, null));

        $this->catalog()->download($entry, null, null);
        $app = AppRecipe::query()->where('slug', 'openvpn')->sole();
        $this->assertSame(AppCatalog::CURRENT, $this->catalog()->status($entry, $app));

        $this->edit('openvpn/recipe.yaml', 'version: 1.3.1', 'version: 1.4.0');
        $this->catalog()->refresh(RecipeSource::official());
        $this->assertSame(AppCatalog::UPDATE_AVAILABLE, $this->catalog()->status($this->entry('openvpn'), $app->refresh()));

        $app->forceFill(['source' => AppRecipe::SOURCE_CUSTOM])->save();
        $this->assertSame(AppCatalog::TAKEN, $this->catalog()->status($this->entry('openvpn'), $app->refresh()));
    }

    public function test_a_custom_app_with_the_slug_is_never_replaced_by_a_download()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $this->catalog()->download($this->entry('openvpn'), null, null);
        AppRecipe::query()->where('slug', 'openvpn')->update(['source' => AppRecipe::SOURCE_CUSTOM]);

        $this->expectException(CatalogRefused::class);
        $this->expectExceptionMessage('A custom app has this slug.');

        $this->catalog()->download($this->entry('openvpn'), null, null);
    }

    public function test_an_app_with_installations_cannot_be_removed()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $this->catalog()->download($this->entry('openvpn'), null, null);
        $app = AppRecipe::query()->where('slug', 'openvpn')->sole();
        AppInstallation::factory()->for($app, 'recipe')->create();

        $this->expectException(CatalogRefused::class);
        $this->expectExceptionMessage('This app has installations; deactivate it instead.');

        $this->catalog()->remove($app, null, null);
    }

    public function test_an_app_without_installations_is_removed_with_its_versions()
    {
        $this->catalog()->refresh(RecipeSource::official());
        $this->catalog()->download($this->entry('wireguard'), null, null);
        $app = AppRecipe::query()->where('slug', 'wireguard')->sole();

        $this->catalog()->remove($app, null, null);

        $this->assertNull(AppRecipe::query()->where('slug', 'wireguard')->first());
        $this->assertSame(0, AppRecipeVersion::query()->count());
        $this->assertSame(['openvpn', 'wireguard'], AppCatalogEntry::query()->orderBy('slug')->pluck('slug')->all(), 'The catalog keeps the recipe.');
    }
}
