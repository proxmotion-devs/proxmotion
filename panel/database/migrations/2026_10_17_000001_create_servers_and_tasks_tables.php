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
        Schema::table('clusters', function (Blueprint $table) {
            // Proxmox resource pool new servers are created in. The API token
            // only needs VM permissions on this pool.
            $table->string('resource_pool', 64)->nullable()->after('verify_tls');
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cluster_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('os_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('vmid');
            $table->string('hostname');
            $table->string('status', 16)->index();
            $table->string('origin', 16)->default('provisioned');
            $table->string('network_model', 16);
            $table->unsignedSmallInteger('cpu_cores');
            $table->unsignedInteger('memory_mib');
            $table->unsignedInteger('disk_mib');
            $table->text('root_password')->nullable(); // encrypted
            $table->text('ssh_keys')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->string('external_id')->nullable()->unique(); // billing service id
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();

            // A VMID can only be used once per cluster; this is what makes
            // VMID allocation race-free.
            $table->unique(['cluster_id', 'vmid']);
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('ip_pool_id')->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('server_id');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who started it
            $table->string('type', 32);
            $table->string('status', 16)->index();
            $table->json('steps');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
            $table->dropColumn('assigned_at');
        });

        Schema::dropIfExists('servers');

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn('resource_pool');
        });
    }
};
