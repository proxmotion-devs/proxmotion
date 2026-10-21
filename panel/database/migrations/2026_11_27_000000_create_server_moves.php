<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Moving servers between nodes, clusters and locations (docs/adr/0037).
        Schema::create('server_moves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // node | cluster | location
            $table->string('kind', 16);
            // queued | running | succeeded | failed
            $table->string('status', 16)->index();
            $table->foreignId('source_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('source_cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('source_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('source_storage_id')->nullable()->constrained('storages')->nullOnDelete();
            $table->unsignedInteger('source_vmid');
            $table->foreignId('target_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('target_cluster_id')->constrained('clusters')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('nodes')->cascadeOnDelete();
            $table->foreignId('target_storage_id')->nullable()->constrained('storages')->nullOnDelete();
            $table->unsignedInteger('target_vmid');
            // Whether the VM was running when the move began (live move, or started again afterwards).
            $table->boolean('was_running')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['target_cluster_id', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('server_move_id')->nullable()->after('os_template_id')->constrained()->nullOnDelete();
        });

        // New addresses a move to another location holds until it is recorded.
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->foreignId('server_move_id')->nullable()->after('server_id')->constrained()->nullOnDelete();
        });

        // The token a cluster receives servers from other clusters with, and its certificate.
        Schema::table('clusters', function (Blueprint $table) {
            $table->string('incoming_token_id')->nullable()->after('token_secret');
            $table->text('incoming_token_secret')->nullable()->after('incoming_token_id'); // encrypted
            $table->string('tls_fingerprint', 95)->nullable()->after('verify_tls');
        });

        // Where a node answers on the API: from the sync, or set by an administrator.
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('address', 45)->nullable()->after('name');
            $table->string('api_host')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['address', 'api_host']);
        });

        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['incoming_token_id', 'incoming_token_secret', 'tls_fingerprint']);
        });

        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_move_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_move_id');
        });

        Schema::dropIfExists('server_moves');
    }
};
