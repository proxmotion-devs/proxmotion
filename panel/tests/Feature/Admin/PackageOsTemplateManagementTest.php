<?php

namespace Tests\Feature\Admin;

use App\Models\Package;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsInfrastructure;

/**
 * The OS templates a package allows in the admin panel (docs/adr/0040).
 */
class PackageOsTemplateManagementTest extends AdminTestCase
{
    use BuildsInfrastructure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'VPS M',
            'cpu_cores' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 40,
            'ipv4_count' => 1,
            'ipv6_count' => 0,
            'snapshot_slots' => 0,
            'backup_slots' => 0,
            'network_models' => ['bridged'],
            'is_active' => true,
            'sort_order' => 0,
            ...$overrides,
        ];
    }

    public function test_packages_save_the_templates_they_allow_and_none_means_any()
    {
        $this->post(route('admin.packages.store'), $this->payload(['os_template_ids' => [$this->osTemplate->id]]))
            ->assertSessionHasNoErrors();

        $package = Package::query()->where('name', 'VPS M')->sole();
        $this->assertSame([$this->osTemplate->id], $package->osTemplates()->pluck('os_templates.id')->all());

        $this->put(route('admin.packages.update', $package), $this->payload(['os_template_ids' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], $package->osTemplates()->pluck('os_templates.id')->all());
    }

    public function test_the_page_lists_the_templates_of_each_package()
    {
        $this->package->osTemplates()->attach($this->osTemplate);

        $this->get(route('admin.packages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/packages/Index')
                ->has('packages', 1)
                ->where('packages.0.os_template_ids', [$this->osTemplate->id])
                ->has('osTemplates', 1));
    }

    public function test_a_template_a_package_names_cannot_be_deleted()
    {
        $this->package->osTemplates()->attach($this->osTemplate);

        $this->delete(route('admin.os-templates.destroy', $this->osTemplate))->assertRedirect();

        $this->assertDatabaseHas('os_templates', ['id' => $this->osTemplate->id]);
    }
}
