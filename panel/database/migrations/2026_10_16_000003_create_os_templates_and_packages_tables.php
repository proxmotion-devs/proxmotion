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
        Schema::create('os_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('family', 32); // ubuntu, debian, almalinux, …
            $table->string('version', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('os_template_proxmox_template', function (Blueprint $table) {
            $table->foreignId('os_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proxmox_template_id')->constrained()->cascadeOnDelete();

            $table->primary(['os_template_id', 'proxmox_template_id']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->unsignedSmallInteger('cpu_cores');
            $table->unsignedInteger('memory_mib');
            $table->unsignedInteger('disk_mib');
            $table->unsignedBigInteger('traffic_bytes')->nullable(); // null = unlimited
            $table->unsignedInteger('port_speed_mbps')->nullable(); // null = unlimited
            $table->unsignedTinyInteger('ipv4_count')->default(1);
            $table->unsignedTinyInteger('ipv6_count')->default(0);
            $table->unsignedTinyInteger('snapshot_slots')->default(0);
            $table->unsignedTinyInteger('backup_slots')->default(0);
            $table->json('network_models');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
        Schema::dropIfExists('os_template_proxmox_template');
        Schema::dropIfExists('os_templates');
    }
};
