<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trial runs of recipe drafts on test servers (docs/adr/0018 §7): a test
 * server flag, trial installations, and frozen copies of the draft each
 * trial installs (status trial, numbered <draft version>-trial.N).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('origin');
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('mode');
        });

        Schema::table('app_recipe_versions', function (Blueprint $table) {
            // 1.2.1-local.3-trial.12 does not fit the earlier 20 characters.
            $table->string('version', 40)->change();
            $table->foreignId('trial_of_id')->nullable()->after('origin')->constrained('app_recipe_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_recipe_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_of_id');
        });

        Schema::table('app_installations', function (Blueprint $table) {
            $table->dropColumn('is_trial');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
