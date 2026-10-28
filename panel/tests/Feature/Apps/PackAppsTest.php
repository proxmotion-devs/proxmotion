<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppPackages;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * apps:pack and apps:validate (docs/adr/0042): the recipes repository holds only
 * <slug>/<slug>.proxapp, built from recipe sources and validated by its CI.
 */
class PackAppsTest extends TestCase
{
    private string $sources;

    private string $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/proxmotion-pack-'.uniqid();
        $this->sources = $root.'/sources';
        $this->repository = $root.'/repository';
        File::copyDirectory(base_path('tests/Fixtures/recipes/openvpn'), $this->sources.'/openvpn');
        File::copyDirectory(base_path('tests/Fixtures/recipes/wireguard'), $this->sources.'/wireguard');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->sources));

        parent::tearDown();
    }

    private function pack(): mixed
    {
        return $this->artisan('apps:pack', ['--path' => $this->sources, '--out' => $this->repository]);
    }

    public function test_every_recipe_is_packed_into_its_own_folder_and_validated()
    {
        $this->pack()->assertSuccessful();

        $this->assertSame(['openvpn.proxapp'], array_values(array_diff((array) scandir($this->repository.'/openvpn'), ['.', '..'])));
        $this->assertFileExists($this->repository.'/wireguard/wireguard.proxapp');
        $this->assertDirectoryDoesNotExist($this->repository.'/packages');
        $this->artisan('apps:validate', ['--path' => $this->repository])->assertSuccessful();

        $package = app(AppPackages::class)->read($this->repository.'/openvpn/openvpn.proxapp');
        $this->assertSame('openvpn', $package['slug']);
        $this->assertSame((string) file_get_contents($this->sources.'/openvpn/install.sh'), $package['files']['install.sh']);
    }

    public function test_an_unchanged_package_is_not_rewritten()
    {
        $this->pack()->assertSuccessful();
        $file = $this->repository.'/openvpn/openvpn.proxapp';
        file_put_contents($file, (string) file_get_contents($file));
        touch($file, 1_000_000_000);
        clearstatcache();

        $this->pack()->expectsOutputToContain('openvpn/openvpn.proxapp is up to date.')->assertSuccessful();
        clearstatcache();
        $this->assertSame(1_000_000_000, filemtime($file));

        file_put_contents($this->sources.'/openvpn/install.sh', "#!/bin/bash\necho changed\n");
        $this->pack()->assertSuccessful();
        clearstatcache();
        $this->assertNotSame(1_000_000_000, filemtime($file));
        $this->assertSame("#!/bin/bash\necho changed\n", app(AppPackages::class)->read($file)['files']['install.sh']);
    }

    public function test_validate_refuses_other_files_a_missing_package_and_a_wrong_folder()
    {
        $this->pack()->assertSuccessful();

        file_put_contents($this->repository.'/openvpn/recipe.yaml', 'slug: openvpn');
        File::ensureDirectoryExists($this->repository.'/empty');
        File::copyDirectory($this->repository.'/wireguard', $this->repository.'/vpn');
        rename($this->repository.'/vpn/wireguard.proxapp', $this->repository.'/vpn/vpn.proxapp');
        File::ensureDirectoryExists($this->repository.'/.github');

        $this->artisan('apps:validate', ['--path' => $this->repository])
            ->expectsOutputToContain('openvpn/recipe.yaml does not belong here; an app folder holds only openvpn.proxapp')
            ->expectsOutputToContain('empty/empty.proxapp is missing')
            ->expectsOutputToContain("The package's recipe.yaml has the slug wireguard; the folder must be named after it")
            ->doesntExpectOutputToContain('.github')
            ->assertFailed();
    }

    public function test_paths_are_required()
    {
        $this->artisan('apps:pack')->assertFailed();
        $this->artisan('apps:pack', ['--path' => $this->sources])->assertFailed();
        $this->artisan('apps:validate')->assertFailed();
    }
}
