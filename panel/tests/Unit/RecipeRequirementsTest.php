<?php

namespace Tests\Unit;

use App\Domain\Apps\Recipe;
use PHPUnit\Framework\TestCase;

/**
 * Which templates a format 2 recipe fits (docs/adr/0024 §4): families
 * like ID_LIKE in /etc/os-release, and single distributions.
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

    public function test_families_cover_their_distributions()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['families' => ['debian', 'rhel']]]);

        $this->assertTrue($recipe->supports('linux', 'debian', '13'));
        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04'));
        $this->assertTrue($recipe->supports('linux', 'almalinux', '9'));
        $this->assertTrue($recipe->supports('linux', 'Rocky', '9'));
        $this->assertTrue($recipe->supports('linux', 'rhel', '9.6', 'almalinux', '9.6'), 'RHEL is matched as AlmaLinux.');
        $this->assertFalse($recipe->supports('linux', 'opensuse-leap', '15.6'));
    }

    public function test_distributions_and_minimum_versions_narrow_it_down()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['distributions' => ['debian']], 'min_versions' => ['debian' => '12']]);

        $this->assertTrue($recipe->supports('linux', 'debian', '13'));
        $this->assertFalse($recipe->supports('linux', 'debian', '11'));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '24.04'), 'Debian alone leaves Ubuntu out.');
        $this->assertTrue($recipe->supports('linux', 'pardus', '23.4', 'debian', '12'), 'A distribution compatible with Debian.');
        $this->assertFalse($recipe->supports('linux', 'pardus', '21', 'debian', '11'), 'Compared with the version it is compatible with.');
    }

    public function test_maximum_versions_leave_out_newer_releases()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['families' => ['debian']], 'min_versions' => ['ubuntu' => '22.04'], 'max_versions' => ['ubuntu' => '24.04', 'debian' => '12']]);

        $this->assertTrue($recipe->supports('linux', 'debian', '12'));
        $this->assertTrue($recipe->supports('linux', 'debian', '12.7'), 'A maximum of 12 covers its point releases.');
        $this->assertTrue($recipe->supports('linux', 'debian', '11'));
        $this->assertFalse($recipe->supports('linux', 'debian', '13'));
        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04.1'));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '24.10'));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '20.04'), 'The minimum still applies.');
        $this->assertFalse($recipe->supports('linux', 'pardus', '25.0', 'debian', '13'));
        $this->assertSame(['linux: debian family', 'ubuntu ≥ 22.04', 'ubuntu ≤ 24.04', 'debian ≤ 12'], $recipe->osSummary());
    }

    public function test_a_recipe_naming_a_distribution_compares_its_own_version()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['distributions' => ['pardus']], 'min_versions' => ['pardus' => '23']]);

        $this->assertTrue($recipe->supports('linux', 'pardus', '23.4', 'debian', '12'));
        $this->assertFalse($recipe->supports('linux', 'pardus', '21', 'debian', '11'));
        $this->assertFalse($recipe->supports('linux', 'debian', '12'));
    }

    public function test_without_os_any_template_of_the_platform_fits()
    {
        $windows = $this->recipe(['platform' => 'windows']);

        $this->assertTrue($windows->supports('windows', 'windows', '2022'));
        $this->assertFalse($windows->supports('linux', 'ubuntu', '24.04'));
        $this->assertTrue($this->recipe(['platform' => 'linux'])->supports('linux', 'arch', '2026'));
        $this->assertSame([], $this->recipe(['platform' => 'linux'])->osReleaseIds());
    }

    public function test_older_recipes_with_a_list_families_or_capabilities_still_load()
    {
        $list = $this->recipe(['platform' => 'linux', 'os' => ['debian', 'almalinux', 'archlinux'], 'min_versions' => ['archlinux' => '2025']]);
        $capabilities = $this->recipe(['platform' => 'linux', 'capabilities' => ['systemd', 'apt|dnf', 'bash']]);
        $families = $this->recipe(['platform' => 'linux', 'families' => ['ubuntu']]);

        $this->assertSame([['debian', 'arch'], ['almalinux']], [$list->families, $list->distributions]);
        $this->assertSame(['arch' => '2025'], $list->minVersions);
        $this->assertTrue($list->supports('linux', 'ubuntu', '24.04'), 'The old group debian is the family.');
        $this->assertFalse($list->supports('linux', 'rocky', '9'));
        $this->assertSame(['debian', 'rhel'], $capabilities->families);
        $this->assertTrue($capabilities->supports('linux', 'rocky', '9'));
        $this->assertSame([null, null], [$this->recipe(['platform' => 'linux', 'capabilities' => ['systemd']])->families, $this->recipe(['platform' => 'linux', 'capabilities' => ['systemd']])->distributions], 'No package manager: any OS.');
        $this->assertSame([[], ['ubuntu']], [$families->families, $families->distributions]);
    }

    public function test_the_runner_gets_the_os_release_ids_to_accept()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['families' => ['debian', 'rhel'], 'distributions' => ['opensuse-leap']]]);

        $this->assertSame(['debian', 'ubuntu', 'rhel', 'centos', 'fedora', 'almalinux', 'rocky', 'ol', 'opensuse-leap'], $recipe->osReleaseIds());
    }

    public function test_the_summary_lists_the_requirements()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'os' => ['families' => ['debian'], 'distributions' => ['almalinux']], 'min_versions' => ['debian' => '12']]);

        $this->assertSame(['linux: debian family, almalinux', 'debian ≥ 12'], $recipe->osSummary());
    }
}
