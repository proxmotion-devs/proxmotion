<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The primary address of each IP version (docs/adr/0011). Without a flag
     * the oldest address of a server is its primary, as before.
     */
    public function up(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
