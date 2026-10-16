<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A billing system may create a server again for a service whose server
     * was terminated (e.g. WHMCS "Create" after "Terminate"). The terminated
     * server keeps its external_id for the history, so uniqueness only covers
     * servers that are not terminated: a virtual generated column holds the
     * external_id of those (NULL otherwise) and carries the unique index.
     *
     * Separate steps, because SQLite rebuilds the table when a column is added
     * or dropped and would lose indexes created in the same step.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('live_external_id')->nullable()
                ->virtualAs("case when status <> 'terminated' then external_id end")
                ->after('external_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['billing_system_id', 'external_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unique(['billing_system_id', 'live_external_id']);
            $table->index(['billing_system_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['billing_system_id', 'external_id']);
            $table->dropUnique(['billing_system_id', 'live_external_id']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('live_external_id');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unique(['billing_system_id', 'external_id']);
        });
    }
};
