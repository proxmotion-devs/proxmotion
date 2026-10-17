<?php

namespace Tests\Unit;

use App\Domain\Apps\Recipe;
use App\Enums\OsPlatform;
use App\Enums\TemplateCapability;
use PHPUnit\Framework\TestCase;

/**
 * Which templates a format 2 recipe fits (docs/adr/0016 §3).
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

    /**
     * @return list<string>
     */
    private function defaults(string $family): array
    {
        return TemplateCapability::defaultsFor(OsPlatform::Linux, $family);
    }

    public function test_alternatives_accept_either_package_manager()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'capabilities' => ['systemd', 'apt|dnf']]);

        $this->assertTrue($recipe->supports('linux', 'debian', '13', $this->defaults('debian')));
        $this->assertTrue($recipe->supports('linux', 'almalinux', '9', $this->defaults('almalinux')));
        $this->assertFalse($recipe->supports('linux', 'opensuse', '15.6', $this->defaults('opensuse')), 'zypper is not one of them.');
        $this->assertFalse($recipe->supports('linux', 'other', '1', ['apt']), 'systemd is missing.');
    }

    public function test_families_and_minimum_versions_narrow_it_down()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'capabilities' => ['apt'], 'families' => ['ubuntu'], 'min_versions' => ['ubuntu' => '22.04']]);

        $this->assertTrue($recipe->supports('linux', 'ubuntu', '24.04', $this->defaults('ubuntu')));
        $this->assertTrue($recipe->supports('linux', 'Ubuntu', '22.04', $this->defaults('ubuntu')));
        $this->assertFalse($recipe->supports('linux', 'ubuntu', '20.04', $this->defaults('ubuntu')));
        $this->assertFalse($recipe->supports('linux', 'debian', '13', $this->defaults('debian')));
    }

    public function test_the_platform_must_match()
    {
        $windows = $this->recipe(['platform' => 'windows', 'capabilities' => ['powershell']]);

        $this->assertTrue($windows->supports('windows', 'windows', '2022', TemplateCapability::defaultsFor(OsPlatform::Windows, 'windows')));
        $this->assertFalse($windows->supports('linux', 'ubuntu', '24.04', ['powershell']));
    }

    public function test_the_summary_lists_the_requirements()
    {
        $recipe = $this->recipe(['platform' => 'linux', 'capabilities' => ['systemd', 'apt|dnf'], 'min_versions' => ['debian' => '12']]);

        $this->assertSame(['linux: systemd, apt|dnf', 'debian ≥ 12'], $recipe->osSummary());
    }
}
