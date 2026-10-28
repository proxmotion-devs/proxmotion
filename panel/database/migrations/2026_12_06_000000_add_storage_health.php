<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storage health (docs/adr/0047): the health of each storage with what is
     * wrong, the state of the pool behind it, the physical disks of each node
     * and the health of a Ceph cluster Proxmox manages. All written by the sync.
     */
    public function up(): void
    {
        Schema::table('storages', function (Blueprint $table) {
            $table->string('health', 16)->default('unknown')->after('used_bytes');
            $table->json('health_problems')->nullable()->after('health');
            $table->string('pool_state', 32)->nullable()->after('health_problems');
            $table->unsignedBigInteger('metadata_used_bytes')->nullable()->after('pool_state');
            $table->unsignedBigInteger('metadata_total_bytes')->nullable()->after('metadata_used_bytes');
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->string('disk_health_error', 500)->nullable()->after('last_seen_at');
        });

        Schema::table('clusters', function (Blueprint $table) {
            $table->string('ceph_health', 32)->nullable()->after('firewall_ebtables');
            $table->json('ceph_checks')->nullable()->after('ceph_health');
        });

        Schema::create('node_disks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('devpath');
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('type', 16)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('used')->nullable();
            $table->string('smart', 32)->nullable();
            $table->unsignedTinyInteger('wearout')->nullable();
            $table->string('health', 16)->default('unknown');
            $table->json('health_problems')->nullable();
            $table->boolean('is_present')->default(true);
            // Not unique per devpath: disks are matched by serial number, and Linux
            // gives a device path to another disk after a reboot.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_disks');

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['ceph_health', 'ceph_checks']);
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('disk_health_error');
        });

        Schema::table('storages', function (Blueprint $table) {
            $table->dropColumn(['health', 'health_problems', 'pool_state', 'metadata_used_bytes', 'metadata_total_bytes']);
        });
    }
};
