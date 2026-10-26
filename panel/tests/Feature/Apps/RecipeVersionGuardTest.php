<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\RecipeLoader;
use App\Domain\Apps\RecipeVersionGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * apps:check-versions (docs/adr/0016 §2): a bundled recipe whose content
 * changed needs a higher version, or installing its package refuses it where the old
 * version is installed. Two recipe directories stand for the base branch
 * and the change.
 */
class RecipeVersionGuardTest extends TestCase
{
    private string $base;

    private string $head;

    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('framework/testing/recipes-'.Str::uuid()->toString());
        $this->base = $root.'/base';
        $this->head = $root.'/head';

        // The loader reads the schema and the shared files from its own directory.
        foreach (['_schema', '_lib', 'docker', 'openvpn'] as $slug) {
            File::copyDirectory(base_path('tests/Fixtures/recipes/'.$slug), $this->base.'/'.$slug);
        }

        File::copyDirectory($this->base, $this->head);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->base));

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function problems(): array
    {
        return (new RecipeVersionGuard)->problems(new RecipeLoader($this->base), new RecipeLoader($this->head));
    }

    private function setVersion(string $slug, string $version): void
    {
        $path = $this->head.'/'.$slug.'/recipe.yaml';
        File::put($path, (string) preg_replace('/^version: .*$/m', 'version: '.$version, File::get($path)));
    }

    public function test_unchanged_recipes_pass()
    {
        $this->assertSame([], $this->problems());
        $this->artisan('apps:check-versions', ['--base-path' => $this->base, '--path' => $this->head])->assertSuccessful();
    }

    public function test_a_changed_script_without_a_new_version_fails()
    {
        File::append($this->head.'/docker/install.sh', "# a comment\n");

        $this->assertSame(['docker: changed but its version is 1.3.1 (was 1.3.1); raise the version in recipe.yaml.'], $this->problems());
        $this->artisan('apps:check-versions', ['--base-path' => $this->base, '--path' => $this->head])
            ->expectsOutputToContain('docker: changed but its version is 1.3.1')
            ->assertFailed();
    }

    public function test_a_changed_recipe_yaml_without_a_new_version_fails()
    {
        $path = $this->head.'/openvpn/recipe.yaml';
        File::put($path, str_replace('min_memory_mib: 512', 'min_memory_mib: 768', File::get($path)));

        $this->assertCount(1, $this->problems());
    }

    public function test_a_change_with_a_higher_version_passes_and_a_lower_one_fails()
    {
        File::append($this->head.'/docker/install.sh', "# a comment\n");

        $this->setVersion('docker', '1.3.2');
        $this->assertSame([], $this->problems());

        $this->setVersion('docker', '1.2.9');
        $this->assertSame(['docker: changed but its version is 1.2.9 (was 1.3.1); raise the version in recipe.yaml.'], $this->problems());
    }

    public function test_readmes_new_and_removed_recipes_do_not_count()
    {
        File::append($this->head.'/docker/README.md', "\nMore notes.\n");
        File::copyDirectory(base_path('tests/Fixtures/recipes/wireguard'), $this->head.'/wireguard');
        File::deleteDirectory($this->head.'/openvpn');

        $this->assertSame([], $this->problems());
    }
}
