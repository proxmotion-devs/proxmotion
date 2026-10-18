<?php

namespace Tests\Feature\Provisioning;

use App\Models\Cluster;
use App\Models\Package;
use App\Models\PlacementGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsInfrastructure;
use Tests\TestCase;

/**
 * The migration that turns package cluster limits into placement groups
 * (docs/adr/0020 §7).
 */
class ConvertPackageClustersTest extends TestCase
{
    use BuildsInfrastructure, RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInfrastructure();
        $this->migration = require database_path('migrations/2026_11_08_000000_convert_package_clusters_to_placement_groups.php');
        // Back to the state before the migration: the table exists again.
        $this->migration->down();
    }

    public function test_each_named_cluster_becomes_one_group_with_all_its_nodes()
    {
        $pve2 = $this->addNode('pve2');
        $other = Package::factory()->create();
        $unlimited = Package::factory()->create();
        $elsewhere = Cluster::factory()->create(['name' => 'ams']);
        DB::table('cluster_package')->insert([
            ['cluster_id' => $this->cluster->id, 'package_id' => $this->package->id],
            ['cluster_id' => $this->cluster->id, 'package_id' => $other->id],
            ['cluster_id' => $elsewhere->id, 'package_id' => $other->id],
        ]);

        $this->migration->up();

        $this->assertFalse(Schema::hasTable('cluster_package'));
        $this->assertSame(2, PlacementGroup::query()->count(), 'One group per cluster, shared by the packages.');

        $group = PlacementGroup::query()->where('location_id', $this->location->id)->sole();
        $this->assertSame("Cluster {$this->cluster->name}", $group->name);
        $this->assertEqualsCanonicalizing([$this->node->id, $pve2->id], $group->nodes()->pluck('nodes.id')->all());
        $this->assertSame(0, $group->storages()->count());

        $this->assertSame([$group->id], $this->package->placementGroups()->pluck('placement_groups.id')->all());
        $this->assertCount(2, $other->placementGroups()->get());
        $this->assertSame(0, $unlimited->placementGroups()->count());
    }
}
