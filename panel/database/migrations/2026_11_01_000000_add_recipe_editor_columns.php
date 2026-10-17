<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin recipe editor (docs/adr/0018): versions are drafts, published or
     * archived, and a custom app remembers the version it was copied from.
     */
    public function up(): void
    {
        Schema::table('app_recipes', function (Blueprint $table) {
            $table->foreignId('copied_from_version_id')->nullable()->after('latest_version_id')->constrained('app_recipe_versions')->nullOnDelete();
        });

        // The latest version of every app is its published one; earlier approved ones are archived.
        DB::table('app_recipe_versions')->where('status', 'approved')->update(['status' => 'archived']);
        DB::table('app_recipe_versions')
            ->whereIn('id', DB::table('app_recipes')->whereNotNull('latest_version_id')->select('latest_version_id'))
            ->update(['status' => 'published']);
    }

    public function down(): void
    {
        DB::table('app_recipe_versions')->whereIn('status', ['published', 'archived'])->update(['status' => 'approved']);
        DB::table('app_recipe_versions')->where('status', 'draft')->delete();

        Schema::table('app_recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copied_from_version_id');
        });
    }
};
