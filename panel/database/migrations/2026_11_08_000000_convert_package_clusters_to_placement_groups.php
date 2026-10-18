<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Packages no longer name clusters (docs/adr/0020 §7): each cluster a
     * package named becomes a placement group with all of the cluster's
     * nodes, so the package keeps placing its servers where it did.
     */
    public function up(): void
    {
        $groupOfCluster = [];

        foreach (DB::table('cluster_package')->orderBy('cluster_id')->get() as $row) {
            $clusterId = (int) $row->cluster_id;

            if (! isset($groupOfCluster[$clusterId])) {
                $cluster = DB::table('clusters')->where('id', $clusterId)->first(['id', 'name', 'location_id']);

                if ($cluster === null) {
                    continue;
                }

                $now = now();
                $groupOfCluster[$clusterId] = DB::table('placement_groups')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'location_id' => $cluster->location_id,
                    'name' => Str::limit("Cluster {$cluster->name}", 100, ''),
                    'description' => 'All nodes of the cluster; created from the package cluster limit.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('node_placement_group')->insert(
                    DB::table('nodes')->where('cluster_id', $clusterId)->pluck('id')
                        ->map(fn ($nodeId) => ['node_id' => $nodeId, 'placement_group_id' => $groupOfCluster[$clusterId]])
                        ->all(),
                );
            }

            DB::table('package_placement_group')->insertOrIgnore([
                'package_id' => $row->package_id,
                'placement_group_id' => $groupOfCluster[$clusterId],
            ]);
        }

        Schema::drop('cluster_package');
    }

    /**
     * The converted groups stay; only the (empty) table comes back.
     */
    public function down(): void
    {
        Schema::create('cluster_package', function (Blueprint $table) {
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['cluster_id', 'package_id']);
        });
    }
};
