<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->char('country_code', 2)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // One Proxmox API connection: a cluster or a standalone node.
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 16)->nullable(); // detected on sync: standalone | cluster
            $table->string('proxmox_cluster_name')->nullable();
            $table->json('hosts');
            $table->unsignedSmallInteger('port')->default(8006);
            $table->string('token_id');
            $table->text('token_secret'); // encrypted
            $table->boolean('verify_tls')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('pve_version', 32)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
        });

        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('unknown');
            $table->boolean('is_maintenance')->default(false);
            $table->unsignedInteger('cpu_cores')->nullable();
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->decimal('cpu_overcommit', 5, 2)->default(4);
            $table->decimal('memory_overcommit', 5, 2)->default(1);
            $table->decimal('disk_overcommit', 5, 2)->default(1);
            $table->unsignedInteger('max_servers')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['cluster_id', 'name']);
        });

        Schema::create('storages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->json('content');
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_available')->default(true);
            $table->boolean('use_for_servers')->default(false);
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'name']);
        });

        Schema::create('network_bridges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_vlan_aware')->default(false);
            $table->string('cidr', 64)->nullable();
            $table->string('comments')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'name']);
        });

        // VM templates found on the cluster; OS templates are mapped onto these.
        Schema::create('proxmox_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('vmid');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['cluster_id', 'vmid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proxmox_templates');
        Schema::dropIfExists('network_bridges');
        Schema::dropIfExists('storages');
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('clusters');
        Schema::dropIfExists('locations');
    }
};
