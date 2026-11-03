<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How often the panel backs up a package's servers (docs/adr/0051 §6): none, daily or weekly.
        Schema::table('packages', function (Blueprint $table) {
            $table->string('backup_schedule', 16)->default('none')->after('backup_slots');
        });

        Schema::table('servers', function (Blueprint $table) {
            // The server's own schedule; null: its package's.
            $table->string('backup_schedule', 16)->nullable()->after('backup_slots');
            // When the schedule last queued a backup of it: once per backup window.
            $table->timestamp('backup_scheduled_at')->nullable()->after('backup_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['backup_schedule', 'backup_scheduled_at']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('backup_schedule');
        });
    }
};
