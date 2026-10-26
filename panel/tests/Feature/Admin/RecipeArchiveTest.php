<?php

namespace Tests\Feature\Admin;

use App\Domain\Apps\AppTrials;
use App\Enums\AppRecipeVersionStatus;
use App\Models\AppRecipe;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

/**
 * Recipes as zips of their format 2 directory (docs/adr/0018 §5): export
 * of any version, import as a new custom app or into a draft, and every
 * archive the panel refuses.
 */
class RecipeArchiveTest extends AdminTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncRecipes();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function app(string $slug): AppRecipe
    {
        return AppRecipe::query()->where('slug', $slug)->sole();
    }

    /**
     * A zip with these entries (path => content); $links are stored as symbolic links.
     *
     * @param  array<string, string>  $entries
     * @param  list<string>  $links
     */
    private function zip(array $entries, array $links = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-recipe');
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);

            if (in_array($name, $links, true)) {
                $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
            }
        }

        $zip->close();

        return new UploadedFile($path, 'recipe.zip', 'application/zip', null, true);
    }

    /**
     * @return array<string, string> entries of an exported zip
     */
    private function entries(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test-export');
        $this->temporary[] = $path;
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();
        ksort($entries);

        return $entries;
    }

    /**
     * The entries of the bundled WireGuard recipe as an export has them.
     *
     * @return array<string, string>
     */
    private function wireguard(string $folder = 'wireguard/'): array
    {
        $version = $this->app('wireguard')->current();
        $entries = [$folder.'recipe.yaml' => Yaml::dump($version->definition, 6, 4)];

        foreach ($version->files as $path => $content) {
            $entries[$folder.$path] = $content;
        }

        return $entries;
    }

    public function test_a_version_is_exported_as_a_proxapp_package_of_its_recipe_directory_and_audited()
    {
        $wireguard = $this->app('wireguard');

        $response = $this->get(route('admin.apps.export', $wireguard))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertDownload('wireguard-1.3.1.proxapp');
        $entries = $this->entries($response);

        $this->assertSame(file_get_contents(base_path('tests/Fixtures/recipes/wireguard/install.sh')), $entries['wireguard/install.sh']);
        $this->assertSame($wireguard->current()->definition, Yaml::parse($entries['wireguard/recipe.yaml']));
        $this->assertSame(['app' => 'wireguard', 'version' => '1.3.1', 'draft' => false], AuditLog::query()->where('action', 'app.exported')->sole()->properties);
    }

    public function test_a_draft_can_be_exported_but_trial_copies_cannot()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');
        $trial = app(AppTrials::class)->freeze($app->draft());

        $this->get(route('admin.apps.export', [$app, 'version' => 'draft']))->assertOk()->assertDownload('my-docker-draft.proxapp');
        $this->get(route('admin.apps.export', $app))->assertOk()->assertDownload('my-docker-draft.proxapp');
        $this->get(route('admin.apps.export', [$app, 'version' => $trial->version]))->assertNotFound();
        $this->get(route('admin.apps.export', [$this->app('docker'), 'version' => '9.9.9']))->assertNotFound();
    }

    public function test_an_exported_recipe_is_imported_as_a_new_custom_draft()
    {
        $export = $this->entries($this->get(route('admin.apps.export', $this->app('wireguard'))));

        $this->post(route('admin.apps.import'), ['archive' => $this->zip($export), 'slug' => 'my-wireguard', 'name' => 'My WireGuard'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.apps.edit', 'my-wireguard'));

        $app = $this->app('my-wireguard');
        $draft = $app->draft();
        $this->assertSame(AppRecipe::SOURCE_CUSTOM, $app->source);
        $this->assertNull($app->latest_version_id, 'An import is a draft until published.');
        $this->assertSame('1.0.0', $draft?->version);
        $this->assertEquals($this->app('wireguard')->current()->files, $draft->files, 'Same files; imports sort them by path.');
        $this->assertSame(['my-wireguard', 'My WireGuard'], [$draft->definition['slug'], $draft->definition['name']]);

        $audit = AuditLog::query()->where('action', 'app.imported')->sole()->properties;
        $this->assertSame('my-wireguard', $audit['app']);
        $this->assertStringContainsString('+++ b/install.sh', $audit['diff']);

        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch'])->assertSessionHasNoErrors();
    }

    public function test_a_recipe_at_the_top_of_the_archive_keeps_its_slug_and_readmes_are_skipped()
    {
        $entries = $this->wireguard('');
        $definition = Yaml::parse($entries['recipe.yaml']);
        $definition['slug'] = 'wg-top';
        $entries['recipe.yaml'] = Yaml::dump($definition, 6, 4);
        $entries['README.md'] = '# Notes';

        $this->post(route('admin.apps.import'), ['archive' => $this->zip($entries)])->assertSessionHasNoErrors();

        $draft = $this->app('wg-top')->draft();
        $this->assertSame('WireGuard', $draft?->definition['name']);
        $this->assertArrayNotHasKey('README.md', $draft->files);
    }

    public function test_an_archive_replaces_the_draft_of_an_existing_app_and_its_slug_stays()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');

        $this->post(route('admin.apps.draft.import', $app), ['archive' => $this->zip($this->wireguard())])->assertSessionHasNoErrors();

        $draft = $app->draft();
        $this->assertSame('my-docker', $draft?->definition['slug']);
        $this->assertSame('1.0.0', $draft->version);
        $this->assertEquals($this->app('wireguard')->current()->files, $draft->files, 'Same files; imports sort them by path.');
        $this->assertSame('app.imported', AuditLog::query()->latest('id')->firstOrFail()->action);
    }

    public function test_archives_that_are_no_recipe_are_refused()
    {
        $notZip = UploadedFile::fake()->createWithContent('recipe.zip', 'not a zip');
        $big = str_repeat('a', 46081);
        $many = [];

        for ($index = 0; $index < 61; $index++) {
            $many['wireguard/actions/a'.$index.'.sh'] = 'echo';
        }

        $cases = [
            'not a zip' => $notZip,
            'no recipe.yaml' => $this->zip(['wireguard/install.sh' => 'echo']),
            'path outside' => $this->zip($this->wireguard() + ['wireguard/../evil.sh' => 'echo']),
            'absolute path' => $this->zip($this->wireguard() + ['/etc/evil.sh' => 'echo']),
            'unknown file' => $this->zip($this->wireguard() + ['wireguard/notes.txt' => 'hi']),
            'large script' => $this->zip(array_replace($this->wireguard(), ['wireguard/install.sh' => $big])),
            'link' => $this->zip($this->wireguard() + ['wireguard/link.sh' => '/etc/shadow'], ['wireguard/link.sh']),
            'too many entries' => $this->zip($this->wireguard() + $many),
            'invalid yaml' => $this->zip(array_replace($this->wireguard(), ['wireguard/recipe.yaml' => "slug: [\n"])),
            'yaml list' => $this->zip(array_replace($this->wireguard(), ['wireguard/recipe.yaml' => "- a\n- b\n"])),
            'not utf-8' => $this->zip(array_replace($this->wireguard(), ['wireguard/install.sh' => "echo \xff\xfe"])),
        ];

        foreach ($cases as $case => $archive) {
            $this->post(route('admin.apps.import'), ['archive' => $archive, 'slug' => 'imported'])->assertSessionHasErrors('archive', $case);
        }

        // The recipe's own slug is taken.
        $this->post(route('admin.apps.import'), ['archive' => $this->zip($this->wireguard())])->assertSessionHasErrors('slug');

        $this->assertNull(AppRecipe::query()->where('slug', 'imported')->first());
        $this->assertSame(0, AuditLog::query()->where('action', 'app.imported')->count());
    }

    public function test_an_imported_recipe_that_does_not_validate_is_kept_as_a_draft_to_fix()
    {
        $entries = $this->wireguard();
        $definition = Yaml::parse($entries['wireguard/recipe.yaml']);
        $definition['category'] = 'not-a-category';
        $entries['wireguard/recipe.yaml'] = Yaml::dump($definition, 6, 4);

        $this->post(route('admin.apps.import'), ['archive' => $this->zip($entries), 'slug' => 'broken-wg'])->assertSessionHasNoErrors();

        $app = $this->app('broken-wg');
        $this->assertSame(AppRecipeVersionStatus::Draft, $app->draft()?->status);
        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch'])->assertSessionHasErrors('draft');
    }
}
