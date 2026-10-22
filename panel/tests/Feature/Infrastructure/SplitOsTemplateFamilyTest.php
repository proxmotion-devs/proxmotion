<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that gives OS templates their own distribution and the
 * real family (docs/adr/0024 §7).
 */
class SplitOsTemplateFamilyTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        // Back to the columns of docs/adr/0022, through the later list of operating systems (docs/adr/0026).
        (require database_path('migrations/2026_11_16_000000_create_operating_systems_tables.php'))->down();
        $this->migration = require database_path('migrations/2026_11_14_000000_split_os_template_family_and_distribution.php');
        $this->migration->down();
    }

    public function test_templates_get_their_distribution_family_and_compatible_system()
    {
        $old = [
            'ubuntu' => ['family' => 'ubuntu', 'version' => '24.04'],
            'arch' => ['family' => 'archlinux', 'version' => '2025'],
            'suse' => ['family' => 'opensuse', 'version' => '15.6'],
            'windows' => ['family' => 'windows', 'version' => '2022', 'platform' => 'windows'],
            'other' => ['family' => 'other', 'version' => '1'],
            'pardus' => ['family' => 'debian', 'version' => '12', 'distribution' => 'pardus', 'distribution_version' => '23.4'],
            'oracle' => ['family' => 'almalinux', 'version' => '9', 'distribution' => 'ol'],
            'unknown base' => ['family' => 'other', 'version' => '1', 'distribution' => 'mydistro', 'distribution_version' => '2'],
        ];

        foreach ($old as $name => $columns) {
            DB::table('os_templates')->insert($columns + ['uuid' => fake()->uuid(), 'name' => $name, 'platform' => 'linux', 'is_active' => true, 'sort_order' => 0]);
        }

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('os_templates', 'distribution_version'));
        $this->assertSame([
            'ubuntu' => ['ubuntu', '24.04', 'debian', null, null],
            'arch' => ['arch', '2025', 'arch', null, null],
            'suse' => ['opensuse-leap', '15.6', 'suse', null, null],
            'windows' => ['windows', '2022', 'windows', null, null],
            'other' => ['linux', '1', 'other', null, null],
            'pardus' => ['pardus', '23.4', 'debian', 'debian', '12'],
            'oracle' => ['ol', '9', 'rhel', 'almalinux', '9'],
            'unknown base' => ['mydistro', '2', 'other', null, null],
        ], DB::table('os_templates')->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->name => [$row->distribution, $row->version, $row->family, $row->compatible_with, $row->compatible_version]])->all());
    }

    public function test_going_back_restores_the_old_columns()
    {
        $this->migration->up();
        DB::table('os_templates')->insert([
            ['uuid' => fake()->uuid(), 'name' => 'Rocky', 'distribution' => 'rocky', 'version' => '9', 'family' => 'rhel', 'compatible_with' => null, 'compatible_version' => null, 'platform' => 'linux', 'is_active' => true, 'sort_order' => 0],
            ['uuid' => fake()->uuid(), 'name' => 'Pardus', 'distribution' => 'pardus', 'version' => '23.4', 'family' => 'debian', 'compatible_with' => 'debian', 'compatible_version' => '12', 'platform' => 'linux', 'is_active' => true, 'sort_order' => 0],
        ]);

        $this->migration->down();

        $this->assertSame([
            ['rocky', '9', null, null],
            ['debian', '12', 'pardus', '23.4'],
        ], DB::table('os_templates')->orderBy('id')->get()->map(fn ($row) => [$row->family, $row->version, $row->distribution, $row->distribution_version])->all());

        $this->migration->up();
    }
}
