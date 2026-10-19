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
                'family' => 'ubuntu',
                'version' => '24.04',
                'distribution' => 'ubuntu',
                'distribution_version' => '24.04',
                'os_family' => 'debian',
            ]]]);
    }

    public function test_family_and_version_keep_their_meaning_for_a_template_matched_as_another()
    {
        // docs/adr/0024 §6: `family` / `version` are what apps are matched as, with the old names.
        $this->osTemplate->forceFill(['distribution' => 'pardus', 'version' => '23.4', 'compatible_with' => 'debian', 'compatible_version' => '12'])->save();
        OsTemplate::factory()->create(['name' => 'Arch', 'distribution' => 'arch', 'version' => '2025.10.01', 'sort_order' => 1]);
        OsTemplate::factory()->create(['name' => 'Zorin', 'distribution' => 'zorin', 'family' => 'debian', 'version' => '17', 'sort_order' => 2]);

        $templates = $this->api('GET', '/api/v1/os-templates')->assertOk()->json('data');

        $this->assertSame(
            [['debian', '12', 'pardus', '23.4', 'debian'], ['archlinux', '2025.10.01', 'arch', '2025.10.01', 'arch'], ['other', '17', 'zorin', '17', 'debian']],
            array_map(fn (array $template) => [$template['family'], $template['version'], $template['distribution'], $template['distribution_version'], $template['os_family']], $templates),
        );
    }
}
