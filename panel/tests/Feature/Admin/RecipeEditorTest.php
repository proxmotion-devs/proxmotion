<?php

namespace Tests\Feature\Admin;

use App\Domain\Apps\AppEligibility;
use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\AppRecipeVersionStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * The admin recipe editor (docs/adr/0018): custom apps, copies, drafts,
 * publishing, deleting; bundled apps stay read-only.
 */
class RecipeEditorTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(RecipeSynchronizer::class)->sync();
    }

    private function app(string $slug): AppRecipe
    {
        return AppRecipe::query()->where('slug', $slug)->sole();
    }

    private function offeredOnUbuntu(AppRecipe $app): bool
    {
        return app(AppEligibility::class)->forCatalog($app->refresh()->load('latestVersion'), OsTemplate::factory()->create(), null) === null;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function save(AppRecipe $app, string $yaml, array $files): TestResponse
    {
        return $this->put(route('admin.apps.draft.save', $app), ['yaml' => $yaml, 'files' => $files]);
    }

    public function test_a_new_app_starts_as_a_draft_that_is_not_offered()
    {
        $this->post(route('admin.apps.store'), ['slug' => 'uptime-kuma', 'name' => 'Uptime Kuma'])->assertSessionHasNoErrors();

        $app = $this->app('uptime-kuma');
        $draft = $app->draft();

        $this->assertSame(AppRecipe::SOURCE_CUSTOM, $app->source);
        $this->assertNull($app->latest_version_id);
        $this->assertNotNull($draft);
        $this->assertSame('1.0.0', $draft->version);
        $this->assertSame(['install.sh', 'uninstall.sh'], array_keys($draft->files));
        $this->assertSame('Uptime Kuma', $draft->definition['name']);
        $this->assertFalse($this->offeredOnUbuntu($app), 'Drafts are never offered.');
        $this->assertSame(1, AuditLog::query()->where('action', 'app.created')->count());
    }

    public function test_slugs_must_be_free_and_well_formed()
    {
        $this->post(route('admin.apps.store'), ['slug' => 'wireguard', 'name' => 'Mine'])->assertSessionHasErrors('slug');
        $this->post(route('admin.apps.store'), ['slug' => 'Bad_Slug', 'name' => 'Mine'])->assertSessionHasErrors('slug');
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'openvpn', 'name' => 'Mine'])->assertSessionHasErrors('slug');

        $this->assertSame(3, AppRecipe::query()->count());
    }

    public function test_a_copy_of_a_bundled_app_is_an_editable_draft_and_the_original_stays()
    {
        $wireguard = $this->app('wireguard');

        $this->post(route('admin.apps.copy', $wireguard), ['slug' => 'my-vpn', 'name' => 'My VPN'])->assertSessionHasNoErrors();

        $copy = $this->app('my-vpn');
        $draft = $copy->draft();
        $this->assertSame($wireguard->latest_version_id, $copy->copied_from_version_id);
        $this->assertSame('1.0.0', $draft?->version);
        $this->assertSame($wireguard->current()->files, $draft->files);
        $this->assertSame(['my-vpn', 'My VPN'], [$draft->definition['slug'], $draft->definition['name']]);
        $this->assertSame(['app' => 'my-vpn', 'from' => 'wireguard@1.2.0'], AuditLog::query()->where('action', 'app.copied')->sole()->properties);

        $this->save($wireguard, 'name: Changed', ['install.sh' => 'echo hi'])->assertSessionHasErrors('app');
        $this->assertSame('WireGuard', $wireguard->current()->refresh()->definition['name'], 'Bundled apps are read-only.');
    }

    public function test_a_draft_is_saved_audited_with_a_diff_and_published_as_a_version()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');
        $draft = $app->draft();
        $yaml = Yaml::dump($draft->definition, 6, 4);
        $files = $draft->files;
        $files['install.sh'] = str_replace('pm_step 85 "Starting Docker"', "pm_step 85 \"Starting Docker\"\r\necho tuned", $files['install.sh']);

        $this->save($app, $yaml, $files)->assertSessionHasNoErrors();

        $saved = $app->draft();
        $this->assertStringContainsString("Starting Docker\"\necho tuned", $saved->files['install.sh'], 'Windows line ends become LF.');
        $this->assertStringContainsString('+echo tuned', AuditLog::query()->where('action', 'app.draft_saved')->sole()->properties['diff']);

        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch'])->assertSessionHasNoErrors();

        $app->refresh();
        $published = $app->current();
        $this->assertSame('1.0.0', $published->version, 'The first version is 1.0.0.');
        $this->assertSame(AppRecipeVersionStatus::Published, $published->status);
        $this->assertSame($this->admin->id, $published->reviewed_by);
        $this->assertNull($app->draft());
        $this->assertTrue($this->offeredOnUbuntu($app));
        $this->assertSame('1.0.0', AuditLog::query()->where('action', 'app.published')->sole()->properties['version']);
    }

    public function test_publishing_again_archives_the_old_version_which_installations_keep()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');
        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch']);
        $first = $app->refresh()->current();
        $installation = AppInstallation::factory()->for($app, 'recipe')->create();

        $this->save($app, Yaml::dump(array_replace($first->definition, ['name' => 'My Docker 2']), 6, 4), $first->files)->assertSessionHasNoErrors();
        $this->assertSame('1.0.1', $app->draft()?->version, 'A new draft suggests the next patch.');
        $this->post(route('admin.apps.publish', $app), ['bump' => 'minor'])->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame(['1.1.0', 'My Docker 2'], [$app->current()->version, $app->name]);
        $this->assertSame(AppRecipeVersionStatus::Archived, $first->refresh()->status);
        $this->assertSame($first->id, $installation->refresh()->app_recipe_version_id);
    }

    public function test_an_invalid_draft_is_saved_but_cannot_be_published()
    {
        $this->post(route('admin.apps.store'), ['slug' => 'broken', 'name' => 'Broken']);
        $app = $this->app('broken');
        $draft = $app->draft();
        $definition = $draft->definition;
        unset($definition['lifecycle']);

        $this->save($app, Yaml::dump($definition, 6, 4), $draft->files)->assertSessionHasNoErrors();
        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch'])->assertSessionHasErrors('draft');

        $this->assertNull($app->refresh()->latest_version_id);
        $this->assertSame(AppRecipeVersionStatus::Draft, $app->draft()?->status);
    }

    public function test_unsafe_file_names_and_bad_yaml_are_refused()
    {
        $this->post(route('admin.apps.store'), ['slug' => 'mine', 'name' => 'Mine']);
        $app = $this->app('mine');

        $this->save($app, 'name: [unclosed', ['install.sh' => 'echo'])->assertSessionHasErrors('yaml');
        $this->save($app, 'name: Mine', ['../etc/passwd.sh' => 'x'])->assertSessionHasErrors('files');
        $this->save($app, 'name: Mine', ['/root/x.sh' => 'x'])->assertSessionHasErrors('files');
        $this->save($app, 'name: Mine', ['notes.txt' => 'x'])->assertSessionHasErrors('files');
    }

    public function test_drafts_can_be_discarded_and_unused_apps_deleted()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');

        $this->delete(route('admin.apps.draft.discard', $app))->assertSessionHasErrors('app', 'Never published: delete the app instead.');
        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch']);
        $this->save($app, Yaml::dump($app->refresh()->current()->definition, 6, 4), $app->current()->files);
        $this->delete(route('admin.apps.draft.discard', $app))->assertSessionHasNoErrors();
        $this->assertNull($app->draft());

        $installation = AppInstallation::factory()->for($app, 'recipe')->create();
        $this->delete(route('admin.apps.destroy', $app))->assertSessionHasErrors('app');

        $installation->delete();
        $this->delete(route('admin.apps.destroy', $app))->assertRedirect(route('admin.apps.index'));
        $this->assertNull(AppRecipe::query()->where('slug', 'my-docker')->first());
        $this->assertSame(0, AppRecipeVersion::query()->where('app_recipe_id', $app->id)->count());
        $this->delete(route('admin.apps.destroy', $this->app('docker')))->assertSessionHasErrors('app');
    }

    public function test_the_apps_page_lists_custom_drafts()
    {
        $this->post(route('admin.apps.store'), ['slug' => 'uptime-kuma', 'name' => 'Uptime Kuma']);

        $this->get(route('admin.apps.index'))->assertInertia(fn ($page) => $page
            ->has('apps', 4)
            ->where('apps.2.slug', 'uptime-kuma')
            ->where('apps.2.source', 'custom')
            ->where('apps.2.published', false)
            ->where('apps.2.draft_version', '1.0.0')
            ->where('apps.2.version', null));
    }

    public function test_the_editor_opens_bundled_apps_read_only_and_custom_drafts_with_their_errors()
    {
        $this->get(route('admin.apps.edit', $this->app('wireguard')))->assertOk()->assertInertia(fn ($page) => $page
            ->component('admin/apps/Edit')
            ->where('app.editable', false)
            ->where('app.source', 'bundled')
            ->where('draft', null)
            ->where('published.version', '1.2.0')
            ->where('published.definition.slug', 'wireguard')
            ->where('published.files', fn ($files) => collect($files)->has('lib.sh'))
            ->where('library', fn ($library) => collect($library)->has('proxmotion.sh'))
            ->where('capabilities', fn ($capabilities) => collect($capabilities)->contains('dnf')));

        $this->post(route('admin.apps.copy', $this->app('wireguard')), ['slug' => 'my-vpn', 'name' => 'My VPN']);

        $this->get(route('admin.apps.edit', $this->app('my-vpn')))->assertInertia(fn ($page) => $page
            ->where('app.editable', true)
            ->where('app.copied_from', ['slug' => 'wireguard', 'name' => 'WireGuard', 'version' => '1.2.0'])
            ->where('app.upstream_version', null)
            ->where('draft.version', '1.0.0')
            ->where('draft.errors', [])
            ->where('versions.0.status', 'draft'));
    }

    public function test_versions_are_compared_in_the_history()
    {
        $this->post(route('admin.apps.copy', $this->app('docker')), ['slug' => 'my-docker', 'name' => 'My Docker']);
        $app = $this->app('my-docker');
        $this->post(route('admin.apps.publish', $app), ['bump' => 'patch']);
        $published = $app->refresh()->current();
        $files = $published->files;
        $files['install.sh'] .= "echo changed\n";
        $this->save($app, Yaml::dump($published->definition, 6, 4), $files);

        $this->getJson(route('admin.apps.diff', ['appRecipe' => $app, 'from' => '1.0.0', 'to' => 'draft']))
            ->assertOk()
            ->assertJsonPath('diff', fn (string $diff) => str_contains($diff, '+echo changed') && str_contains($diff, '--- a/install.sh'));
        $this->getJson(route('admin.apps.diff', ['appRecipe' => $app, 'from' => '9.9.9', 'to' => 'draft']))->assertNotFound();
    }

    public function test_sync_never_replaces_a_custom_app_with_the_same_slug()
    {
        // A custom app made before a panel update brought a bundled recipe with its slug.
        $bundled = $this->app('docker');
        $bundled->forceFill(['latest_version_id' => null])->save();
        $bundled->versions()->delete();
        $bundled->delete();
        $custom = AppRecipe::query()->forceCreate(['slug' => 'docker', 'source' => AppRecipe::SOURCE_CUSTOM, 'name' => 'My own Docker', 'category' => 'container', 'is_active' => true]);

        $this->assertSame(['docker'], app(RecipeSynchronizer::class)->sync()['skipped']);
        $this->assertSame(AppRecipe::SOURCE_CUSTOM, $custom->refresh()->source);
        $this->assertSame(0, $custom->versions()->count());
    }
}
