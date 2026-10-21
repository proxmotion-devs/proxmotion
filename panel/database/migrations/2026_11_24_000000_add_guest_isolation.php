<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            // Servers with addresses of the pool may not talk to each other directly (docs/adr/0034).
            $table->boolean('isolate_guests')->default(true)->after('is_active');
        });

        Schema::table('locations', function (Blueprint $table) {
            // CIDRs servers of the location may not open connections to (docs/adr/0034).
            $table->json('protected_networks')->nullable()->after('hostname_template');
        });
    }

    public function down(): void
    {
        Schema::table('ip_pools', function (Blueprint $table) {
            $table->dropColumn('isolate_guests');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('protected_networks');
        });
    }
};
