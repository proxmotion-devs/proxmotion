<?php

namespace Tests\Feature\Infrastructure;

use App\Models\AppRecipe;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that moves the systems of OS templates into the
 * administrators' list and ticks apps where they were offered (docs/adr/0026).
 */
class OperatingSystemsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncRecipes();
        $this->migration = require database_path('migrations/2026_11_16_000000_create_operating_systems_tables.php');
        $this->migration->down();
    }

    public function test_templates_get_listed_versions_and_apps_stay_where_they_were_offered()
    {
        $templates = [
            'Ubuntu 24.04' => ['distribution' => 'ubuntu', 'version' => '24.04', 'family' => 'debian'],
            'Ubuntu 24.04 (copy)' => ['distribution' => 'ubuntu', 'version' => '24.04', 'family' => 'debian'],
            'Debian 11' => ['distribution' => 'debian', 'version' => '11', 'family' => 'debian'],
            'Pardus 23.4' => ['distribution' => 'pardus', 'version' => '23.4', 'family' => 'debian', 'compatible_with' => 'debian', 'compatible_version' => '12'],
            'Windows' => ['distribution' => 'windows', 'version' => '2022', 'family' => 'windows', 'platform' => 'windows'],
        ];

        foreach ($templates as $name => $columns) {
            DB::table('os_templates')->insert($columns + ['uuid' => fake()->uuid(), 'name' => $name, 'platform' => 'linux', 'is_active' => true, 'sort_order' => 0]);
        }

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('os_templates', 'compatible_with'));
        $this->assertSame(
            [['Ubuntu', 'ubuntu', 'linux'], ['Debian', 'debian', 'linux'], ['Pardus', 'pardus', 'linux'], ['Windows', 'windows', 'windows']],
            DB::table('operating_systems')->orderBy('sort_order')->get()->map(fn ($row) => [$row->name, $row->slug, $row->platform])->all(),
        );
        $this->assertSame(4, DB::table('operating_system_versions')->count(), 'The copy shares the version.');
        $this->assertSame(0, DB::table('os_templates')->whereNull('operating_system_version_id')->count());

        $version = fn (string $template) => DB::table('os_templates')->where('name', $template)->value('operating_system_version_id');
        $wireguard = AppRecipe::query()->where('slug', 'wireguard')->sole()->osVersions()->pluck('operating_system_versions.id')->all();

        // WireGuard needs Ubuntu 22.04 or Debian 12 and newer; Pardus 23 counted as Debian 12.
        $this->assertEqualsCanonicalizing([$version('Ubuntu 24.04'), $version('Pardus 23.4')], $wireguard);
    }

    public function test_going_back_restores_the_columns()
    {
        $this->migration->up();
        $this->migration->down();

        $this->assertTrue(Schema::hasColumn('os_templates', 'compatible_with'));
        $this->assertFalse(Schema::hasColumn('os_templates', 'operating_system_version_id'));
        $this->assertFalse(Schema::hasTable('operating_systems'));
    }
}
