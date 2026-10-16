<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Differences between the panel and Proxmox found by the cluster sync (ADR 0005).
     */
    public function up(): void
    {
        Schema::create('server_drifts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('vmid');
            $table->string('type', 32);
            $table->json('expected')->nullable();
            $table->json('actual')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            // Hidden by an admin while it lasts.
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cluster_id', 'resolved_at']);
            $table->index(['server_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_drifts');
    }
};
