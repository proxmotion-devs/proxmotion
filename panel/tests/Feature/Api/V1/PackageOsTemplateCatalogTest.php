<?php

namespace Tests\Feature\Api\V1;

use App\Models\OsTemplate;
use Illuminate\Support\Str;

/**
 * Which OS templates a package allows, through the billing API (docs/adr/0040).
 */
class PackageOsTemplateCatalogTest extends ApiTestCase
{
    public function test_packages_list_the_templates_they_allow()
    {
        $this->package->osTemplates()->attach($this->osTemplate);

        $this->api('GET', '/api/v1/packages')
            ->assertOk()
            ->assertJsonPath('data.0.os_templates', [$this->osTemplate->uuid]);
    }

    public function test_the_package_filter_lists_only_the_templates_it_allows()
    {
        $this->osTemplate->update(['is_active' => true]);
        $allowed = OsTemplate::factory()->create(['is_active' => true]);
        OsTemplate::factory()->create(['is_active' => true]);
        $this->package->osTemplates()->attach($allowed);

        $this->api('GET', '/api/v1/os-templates?package='.$this->package->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->uuid);
    }

    public function test_the_server_filter_lists_the_templates_its_server_may_be_reinstalled_with()
    {
        $this->osTemplate->update(['is_active' => true]);
        $server = $this->provisionedServer();

        $this->api('GET', '/api/v1/os-templates?server='.$server->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->osTemplate->uuid);
    }

    public function test_the_package_and_server_filters_cannot_be_combined()
    {
        $this->api('GET', '/api/v1/os-templates?package='.$this->package->uuid.'&server='.Str::uuid())
            ->assertUnprocessable();
    }
}
