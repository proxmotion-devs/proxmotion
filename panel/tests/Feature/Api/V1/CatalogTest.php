<?php

namespace Tests\Feature\Api\V1;

use App\Models\OsTemplate;
use App\Models\Package;

class CatalogTest extends ApiTestCase
{
    public function test_active_packages_are_listed()
    {
        Package::factory()->create(['name' => 'Retired', 'is_active' => false]);

        $this->api('GET', '/api/v1/packages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertExactJson(['data' => [[
                'id' => $this->package->uuid,
                'name' => $this->package->name,
                'cpu_cores' => 2,
                'memory_mib' => 4096,
                'disk_mib' => 40960,
                'ipv4_count' => 1,
                'ipv6_count' => 0,
                'port_speed_mbps' => 1000,
                'traffic_bytes' => $this->package->traffic_bytes,
                'network_models' => ['bridged', 'routed'],
            ]]]);
    }

    public function test_locations_are_listed()
    {
        $this->api('GET', '/api/v1/locations')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $this->location->uuid,
                'name' => $this->location->name,
                'code' => 'ist1',
                'country_code' => $this->location->country_code,
            ]]]);
    }

    public function test_active_os_templates_are_listed()
    {
        OsTemplate::factory()->create(['is_active' => false]);

        $this->api('GET', '/api/v1/os-templates')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'id' => $this->osTemplate->uuid,
                'name' => $this->osTemplate->name,
                'family' => $this->osTemplate->family,
                'version' => $this->osTemplate->version,
            ]]]);
    }
}
