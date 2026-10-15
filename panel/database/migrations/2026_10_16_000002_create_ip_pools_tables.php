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
        Schema::create('ip_pools', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('version'); // 4 | 6
            $table->string('network_model', 16); // bridged | routed | nat
            $table->string('network', 64); // CIDR, e.g. 203.0.113.0/24
            $table->string('gateway', 45)->nullable();
            $table->string('bridge', 32)->default('vmbr0');
            $table->unsignedSmallInteger('vlan_tag')->nullable();
            $table->json('nameservers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_pool_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45)->unique();
            $table->string('mac_address', 17)->nullable(); // provider virtual MAC (routed IPs)
            $table->boolean('is_reserved')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['ip_pool_id', 'is_reserved']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('ip_pools');
    }
};
