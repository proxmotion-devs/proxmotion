<?php

namespace Tests\Unit;

use App\Domain\Apps\OsFamilies;
use App\Domain\Apps\Recipe;
use PHPUnit\Framework\TestCase;

/**
 * Which templates a format 2 recipe fits (docs/adr/0016 §3): template
 * families or groups of them, like ID_LIKE in /etc/os-release.
 */
class RecipeRequirementsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $requires
     */
    private function recipe(array $requires): Recipe
    {
        return Recipe::fromArray([
            'format' => 2,
            'slug' => 'demo',
            'name' => 'Demo',
            'version' => '1.0.0',
            'category' => 'web',
            'description' => ['en' => 'Demo'],
            'requires' => $requires,
            'resources' => ['min_memory_mib' => 512, 'min_disk_mib' => 2048, 'min_cores' => 1],
            'network_models' => ['bridged'],
            'install_modes' => ['post'],
            'billing' => 'free',
            'license' => 'none',
            'lifecycle' => ['install' => ['script' => 'install.sh', 'timeout_minutes' => 5]],
        ]);
    }

    public function test_groups_cover_related_families()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['debian', 'rhel']]);

        $this->assertTrue($recipe->supports('linux', 'debian', '13'));
        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04'));
        $this->assertTrue($recipe->supports('linux', 'almalinux', '9'));
        $this->assertTrue($recipe->supports('linux', 'Rocky', '9'));
        $this->assertFalse($recipe->supports('linux', 'opensuse', '15.6'));
        $this->assertFalse($recipe->supports('linux', 'other', '1'));
    }

    public function test_families_and_minimum_versions_narrow_it_down()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['ubuntu'], 'min_versions' => ['ubuntu' => '22.04']]);

        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04'));
        $this->assertTrue($recipe->supports('linux', 'Ubuntu', '22.04'));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '20.04'));
        $this->assertFalse($recipe->supports('linux', 'debian', '13'), 'A family is not its group.');
    }

    public function test_without_os_any_template_of_the_platform_fits()
    {
        $windows = $this->recipe(['platform' => 'windows']);

        $this->assertTrue($windows->supports('windows', 'windows', '2022'));
        $this->assertFalse($windows->supports('linux', 'ubuntu', '24.04'));
        $this->assertTrue($this->recipe(['platform' => 'linux'])->supports('linux', 'archlinux', '2026'));
    }

    public function test_older_recipes_with_families_or_capabilities_still_load()
    {
        $capabilities = $this->recipe(['platform' => 'linux', 'capabilities' => ['systemd', 'apt|dnf', 'bash']]);
        $families = $this->recipe(['platform' => 'linux', 'families' => ['ubuntu']]);

        $this->assertSame(['debian', 'rhel'], $capabilities->families);
        $this->assertTrue($capabilities->supports('linux', 'rocky', '9'));
        $this->assertNull($this->recipe(['platform' => 'linux', 'capabilities' => ['systemd']])->families, 'No package manager: any OS.');
        $this->assertSame(['ubuntu'], $families->families);
    }

    public function test_the_runner_gets_the_os_release_ids_to_accept()
    {
        $this->assertSame(['debian', 'ubuntu', 'rhel', 'centos', 'fedora', 'almalinux', 'rocky'], OsFamilies::osReleaseIds(['debian', 'rhel']));
        $this->assertSame(['ubuntu'], OsFamilies::osReleaseIds(['ubuntu']));
        $this->assertSame([], OsFamilies::osReleaseIds(null));
        $this->assertSame(['debian', 'ubuntu', 'rocky'], OsFamilies::expand(['debian', 'rocky']));
    }

    public function test_the_summary_lists_the_requirements()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['debian', 'rhel'], 'min_versions' => ['debian' => '12']]);

        $this->assertSame(['linux: debian, rhel', 'debian ≥ 12'], $recipe->osSummary());
    }
}
