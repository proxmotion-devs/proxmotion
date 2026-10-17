<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recipes name OS families and groups (docs/adr/0016 §3), so templates
     * no longer list capabilities. Bundled versions remember whether they
     * came from panel/recipes or an administrator edited the app in place
     * (docs/adr/0018 §2).
     */
    public function up(): void
    {
        if (Schema::hasColumn('os_templates', 'capabilities')) {
            Schema::table('os_templates', function (Blueprint $table) {
                $table->dropColumn('capabilities');
            });
        }

        Schema::table('app_recipe_versions', function (Blueprint $table) {
            $table->string('origin', 20)->default('local')->after('status');
        });

        // Versions of bundled apps so far all came from panel/recipes.
        DB::table('app_recipe_versions')
            ->whereIn('app_recipe_id', DB::table('app_recipes')->where('source', 'bundled')->select('id'))
            ->update(['origin' => 'upstream']);
    }

    public function down(): void
    {
        Schema::table('app_recipe_versions', function (Blueprint $table) {
            $table->dropColumn('origin');
        });

        Schema::table('os_templates', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('platform');
        });
    }
};
