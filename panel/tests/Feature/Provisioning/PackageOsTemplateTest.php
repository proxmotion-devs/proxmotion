<?php

namespace Tests\Feature\Provisioning;

use App\Models\OsTemplate;
use App\Models\Server;
use App\Provisioning\ServerCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsInfrastructure;
use Tests\Support\FakeProxmoxCluster;
use Tests\TestCase;

/**
 * A package may name the OS templates its servers use; naming none means any,
 * and a custom server (no package) may use any (docs/adr/0040).
 */
class PackageOsTemplateTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        (new FakeProxmoxCluster)->addTemplate(9000)->install();
    }

    private function create(array $overrides = []): Server
    {
        return app(ServerCreator::class)->create($this->order($overrides));
    }

    public function test_a_package_refuses_a_template_it_does_not_name()
    {
        $this->package->osTemplates()->attach(OsTemplate::factory()->create(['is_active' => true]));

        $this->expectExceptionMessage('This package may not use the chosen OS template.');
        $this->create();
    }

    public function test_a_package_creates_servers_with_a_template_it_names()
    {
        $this->package->osTemplates()->attach($this->osTemplate);

        $this->assertTrue($this->create()->osTemplate->is($this->osTemplate));
    }

    public function test_a_package_naming_no_template_allows_any()
    {
        $this->assertTrue($this->create()->osTemplate->is($this->osTemplate));
    }

    public function test_a_custom_server_without_a_package_may_use_any_template()
    {
        $this->package->osTemplates()->attach(OsTemplate::factory()->create(['is_active' => true]));

        $this->assertTrue($this->create(['package' => null])->osTemplate->is($this->osTemplate));
    }
}
