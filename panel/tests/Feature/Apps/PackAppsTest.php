<?php

namespace Tests\Feature\Apps;

use App\Domain\Apps\AppPackages;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * apps:pack (docs/adr/0042): the recipes repository keeps a .proxapp of every recipe in packages/,
 * and its CI checks they match the recipes.
 */
class PackAppsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/proxmotion-pack-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/recipes/openvpn'), $this->directory.'/openvpn');
        File::copyDirectory(base_path('tests/Fixtures/recipes/wireguard'), $this->directory.'/wireguard');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_every_recipe_is_packed_and_the_packages_are_checked()
    {
        $this->artisan('apps:pack', ['--path' => $this->directory, '--check' => true])->assertFailed();

        $this->artisan('apps:pack', ['--path' => $this->directory])->assertSuccessful();

        $this->assertFileExists($this->directory.'/packages/openvpn.proxapp');
        $this->assertFileExists($this->directory.'/packages/wireguard.proxapp');
        $this->artisan('apps:pack', ['--path' => $this->directory, '--check' => true])->assertSuccessful();
        $this->artisan('apps:validate', ['--path' => $this->directory])->assertSuccessful();

        $package = app(AppPackages::class)->read($this->directory.'/packages/openvpn.proxapp');
        $this->assertSame('openvpn', $package['slug']);
        $this->assertSame((string) file_get_contents($this->directory.'/openvpn/install.sh'), $package['files']['install.sh']);
    }

    public function test_a_changed_recipe_or_a_package_without_a_recipe_fails_the_check()
    {
        $this->artisan('apps:pack', ['--path' => $this->directory])->assertSuccessful();

        file_put_contents($this->directory.'/openvpn/install.sh', "#!/bin/bash\necho changed\n");
        $this->artisan('apps:pack', ['--path' => $this->directory, '--check' => true])
            ->expectsOutputToContain('packages/openvpn.proxapp differs from its recipe')
            ->assertFailed();

        $this->artisan('apps:pack', ['--path' => $this->directory])->assertSuccessful();
        File::deleteDirectory($this->directory.'/wireguard');
        $this->artisan('apps:pack', ['--path' => $this->directory, '--check' => true])
            ->expectsOutputToContain('packages/wireguard.proxapp has no recipe')
            ->assertFailed();

        $this->artisan('apps:pack', ['--path' => $this->directory])->assertSuccessful();
        $this->assertFileDoesNotExist($this->directory.'/packages/wireguard.proxapp');
    }

    public function test_a_path_is_required()
    {
        $this->artisan('apps:pack')->assertFailed();
    }
}
