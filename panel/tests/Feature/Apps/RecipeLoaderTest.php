<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\RecipeInvalid;
use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeSynchronizer;
use App\Enums\AppRecipeVersionStatus;
use App\Models\AppInstallation;
use App\Models\AppRecipe;
use App\Models\AppRecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Recipe validation (apps:validate), syncing with stored versions (apps:sync)
 * and the skeleton (apps:make), docs/adr/0015 and 0016.
 */
class RecipeLoaderTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // A copy of the schema and helpers with one valid recipe to break in each test.
        $this->directory = sys_get_temp_dir().'/proxmotion-recipes-'.uniqid();
        File::copyDirectory(base_path('recipes/_schema'), $this->directory.'/_schema');
        File::copyDirectory(base_path('recipes/_lib'), $this->directory.'/_lib');
        File::copyDirectory(base_path('recipes/openvpn'), $this->directory.'/openvpn');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function loader(): RecipeLoader
    {
        return new RecipeLoader($this->directory);
    }

    private function editRecipe(string $search, string $replace): void
    {
        $file = $this->directory.'/openvpn/recipe.yaml';
        $content = (string) file_get_contents($file);
        $this->assertStringContainsString($search, $content);
        file_put_contents($file, str_replace($search, $replace, $content));
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        try {
            $this->loader()->load('openvpn');
        } catch (RecipeInvalid $e) {
            return $e->errors;
        }

        $this->fail('The recipe was accepted.');
    }

    public function test_the_bundled_recipes_are_valid()
    {
        $recipes = (new RecipeLoader)->all();

        $this->assertSame(['aapanel', 'coolify', 'cpanel', 'cyberpanel', 'docker', 'fastpanel', 'hestiacp', 'lamp', 'lemp', 'nodejs', 'openvpn', 'plesk', 'portainer', 'whmcs', 'wireguard', 'wordpress'], array_keys($recipes));
        $this->assertSame(['install.sh', 'uninstall.sh', 'upgrade.sh', 'actions/add-client.sh', 'actions/revoke-client.sh', 'actions/list-clients.sh', 'lib.sh'], array_keys($recipes['wireguard']['files']));
        $this->artisan('apps:validate')->assertSuccessful();
    }

    public function test_inputs_labelled_optional_are_not_required()
    {
        // Without a default an input is required unless the recipe says required: false.
        foreach ((new RecipeLoader)->all() as $slug => ['recipe' => $recipe]) {
            $inputs = $recipe->inputs;

            foreach ($recipe->actions as $action) {
                $inputs = [...$inputs, ...$action->inputs];
            }

            foreach ($inputs as $input) {
                if (str_contains(strtolower($input->label['en'] ?? ''), 'optional')) {
                    $this->assertFalse($input->required, "{$slug}: {$input->key} is labelled optional but required.");
                }
            }
        }
    }

    public function test_the_schema_is_enforced()
    {
        $this->editRecipe('category: vpn', 'category: games');

        $this->assertStringContainsString('/category', implode("\n", $this->errors()));
    }

    public function test_the_slug_must_be_the_directory_name()
    {
        $this->editRecipe('slug: openvpn', 'slug: open-vpn');

        $this->assertContains('slug must be the directory name (openvpn).', $this->errors());
    }

    public function test_install_sh_must_exist()
    {
        unlink($this->directory.'/openvpn/install.sh');

        $this->assertContains('install.sh is missing.', $this->errors());
    }

    public function test_defaults_must_fit_their_input()
    {
        $this->editRecipe('default: udp', 'default: icmp');

        $this->assertStringContainsString('Input protocol: the default is invalid', implode("\n", $this->errors()));
    }

    public function test_firewall_rules_may_only_refer_to_declared_inputs()
    {
        $this->editRecipe("'{{ inputs.port }}'", "'{{ inputs.listen_port }}'");

        $this->assertContains('Firewall rule refers to the unknown input listen_port.', $this->errors());
    }

    public function test_apps_validate_reports_invalid_recipes()
    {
        $this->editRecipe('version: 1.3.1', 'version: one');
        $this->app->instance(RecipeLoader::class, $this->loader());

        $this->artisan('apps:validate')->assertFailed();
    }

    public function test_sync_adds_updates_and_deactivates_recipes_but_keeps_an_admins_choice()
    {
        $this->app->instance(RecipeLoader::class, $this->loader());
        $sync = fn () => app(RecipeSynchronizer::class)->sync();

        $this->assertSame(['created' => ['openvpn'], 'updated' => [], 'held' => [], 'deactivated' => [], 'skipped' => []], $sync());
        $recipe = AppRecipe::query()->where('slug', 'openvpn')->sole();
        $this->assertTrue($recipe->is_active);
        $this->assertSame((string) file_get_contents($this->directory.'/openvpn/install.sh'), $recipe->current()->files['install.sh']);
        $this->assertSame(AppRecipeVersionStatus::Published, $recipe->current()->status, 'Bundled recipes ship with the panel.');
        $this->assertSame(2, $recipe->current()->format);

        $this->assertSame(['created' => [], 'updated' => [], 'held' => [], 'deactivated' => [], 'skipped' => []], $sync(), 'Nothing changed.');

        $recipe->forceFill(['is_active' => false])->save();
        $this->editRecipe('version: 1.3.1', 'version: 1.4.0');
        $this->assertSame(['created' => [], 'updated' => ['openvpn'], 'held' => [], 'deactivated' => [], 'skipped' => []], $sync());
        $recipe->refresh();
        $this->assertSame('1.4.0', $recipe->current()->version);
        $this->assertSame(['1.3.1', '1.4.0'], $recipe->versions()->orderBy('id')->pluck('version')->all(), 'Earlier versions are kept.');
        $this->assertFalse($recipe->is_active, 'The administrator deactivated it.');

        $recipe->forceFill(['is_active' => true])->save();
        File::deleteDirectory($this->directory.'/openvpn');
        $this->assertSame(['created' => [], 'updated' => [], 'held' => [], 'deactivated' => ['openvpn'], 'skipped' => []], $sync());
        $this->assertFalse($recipe->refresh()->is_active);
    }

    public function test_a_version_in_use_cannot_change_without_a_new_number()
    {
        $this->app->instance(RecipeLoader::class, $this->loader());
        app(RecipeSynchronizer::class)->sync();
        $recipe = AppRecipe::query()->where('slug', 'openvpn')->sole();
        AppInstallation::factory()->for($recipe, 'recipe')->create();

        file_put_contents($this->directory.'/openvpn/install.sh', "#!/bin/bash\necho changed\n");

        try {
            app(RecipeSynchronizer::class)->sync();
            $this->fail('The changed version was accepted.');
        } catch (RecipeInvalid $e) {
            $this->assertSame(['Version 1.3.1 changed but is used by installations: raise the version.'], $e->errors);
        }

        $this->assertStringNotContainsString('changed', $recipe->current()->refresh()->files['install.sh']);
    }

    public function test_an_unused_version_is_replaced_in_place()
    {
        $this->app->instance(RecipeLoader::class, $this->loader());
        app(RecipeSynchronizer::class)->sync();

        file_put_contents($this->directory.'/openvpn/install.sh', "#!/bin/bash\necho changed\n");

        $this->assertSame(['created' => [], 'updated' => ['openvpn'], 'held' => [], 'deactivated' => [], 'skipped' => []], app(RecipeSynchronizer::class)->sync());
        $this->assertSame(1, AppRecipeVersion::query()->count());
        $this->assertStringContainsString('changed', AppRecipeVersion::query()->sole()->files['install.sh']);
    }

    public function test_format_1_recipes_still_load()
    {
        File::ensureDirectoryExists($this->directory.'/legacy');
        file_put_contents($this->directory.'/legacy/install.sh', "#!/bin/bash\necho legacy\n");
        file_put_contents($this->directory.'/legacy/recipe.yaml', <<<'YAML'
            slug: legacy
            name: Legacy
            version: 1.0.0
            category: web
            description: { en: A format 1 recipe. }
            os: [{ family: ubuntu, versions: ['24.04'] }]
            requirements: { min_memory_mib: 512, min_disk_mib: 2048, min_cores: 1 }
            network_models: [bridged]
            install_modes: [post]
            billing: free
            license: none
            timeout_minutes: 7
            YAML);

        ['recipe' => $recipe, 'files' => $files] = $this->loader()->load('legacy');

        $this->assertSame(1, $recipe->format);
        $this->assertSame(['install' => ['script' => 'install.sh', 'timeout_minutes' => 7]], $recipe->lifecycle);
        $this->assertSame(['install.sh'], array_keys($files));
        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04'));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '22.04'), 'Format 1 lists exact versions.');
        $this->assertTrue($recipe->supports('linux', 'linuxmint', '22', 'ubuntu', '24.04'), 'Matched as the distribution it is compatible with.');
    }

    public function test_unknown_formats_are_refused()
    {
        $this->editRecipe('format: 2', 'format: 3');

        $this->assertSame(['format: this panel reads recipe formats 1 and 2.'], $this->errors());
    }

    public function test_lifecycle_scripts_must_exist_and_fit_the_platform()
    {
        $this->editRecipe('uninstall: { script: uninstall.sh,', 'uninstall: { script: scripts/uninstall.ps1,');
        $this->editRecipe('upgrade: { script: upgrade.sh,', 'upgrade: { script: upgrade-v2.sh,');

        $errors = $this->errors();

        $this->assertContains('scripts/uninstall.ps1: Linux recipes use .sh scripts.', $errors);
        $this->assertContains('upgrade-v2.sh is missing.', $errors);
    }

    public function test_scripts_cannot_leave_the_recipe_directory()
    {
        $this->editRecipe('install: { script: install.sh,', 'install: { script: ../openvpn/install.sh,');

        $this->assertStringContainsString('/lifecycle/install/script', implode("\n", $this->errors()));
    }

    public function test_min_versions_must_name_families_the_os_covers()
    {
        $this->editRecipe("min_versions: { ubuntu: '22.04', debian: '12' }", "min_versions: { ubuntu: '22.04', rocky: '9' }");

        $this->assertContains('requires.min_versions: rocky is not covered by requires.os.', $this->errors());
    }

    public function test_max_versions_must_name_families_the_os_covers()
    {
        $this->editRecipe("min_versions: { ubuntu: '22.04', debian: '12' }", "min_versions: { ubuntu: '22.04', debian: '12' }\n    max_versions: { debian: '12', rocky: '9' }");

        $this->assertContains('requires.max_versions: rocky is not covered by requires.os.', $this->errors());
        $this->assertNotContains('requires.max_versions: debian is not covered by requires.os.', $this->errors());
    }

    public function test_max_versions_must_be_version_numbers()
    {
        $this->editRecipe("min_versions: { ubuntu: '22.04', debian: '12' }", "min_versions: { ubuntu: '22.04', debian: '12' }\n    max_versions: { debian: trixie }");

        $this->assertStringContainsString('/requires/max_versions/debian', implode("\n", $this->errors()));
    }

    public function test_apps_make_writes_a_valid_skeleton()
    {
        $this->app->instance(RecipeLoader::class, $this->loader());

        $this->artisan('apps:make', ['slug' => 'uptime-kuma', '--name' => 'Uptime Kuma'])->assertSuccessful();

        ['recipe' => $recipe, 'files' => $files] = $this->loader()->load('uptime-kuma');
        $this->assertSame('Uptime Kuma', $recipe->name);
        $this->assertSame(['install.sh', 'uninstall.sh'], array_keys($files));
        $this->assertFileExists($this->directory.'/uptime-kuma/README.md');

        $this->artisan('apps:make', ['slug' => 'uptime-kuma'])->assertFailed();
        $this->artisan('apps:make', ['slug' => 'Bad_Slug'])->assertFailed();
    }

    public function test_an_invalid_recipe_stops_the_sync_before_anything_is_written()
    {
        $this->editRecipe('timeout_minutes: 15', 'timeout_minutes: 0');
        $this->app->instance(RecipeLoader::class, $this->loader());

        $this->artisan('apps:sync')->assertFailed();
        $this->assertSame(0, AppRecipe::query()->count());
    }
}
