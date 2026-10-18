<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrades (docs/adr/0016 §4): the version an upgrade run moves the
     * installation to. It stays the installation's old version until the
     * run succeeds.
     */
    public function up(): void
    {
        Schema::table('app_runs', function (Blueprint $table) {
            $table->foreignId('target_version_id')->nullable()->after('action')->constrained('app_recipe_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_version_id');
        });
    }
};
