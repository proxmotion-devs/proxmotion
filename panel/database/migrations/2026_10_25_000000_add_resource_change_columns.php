<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Changing a server's resources: CPU and memory of a running VM take effect
     * at its next restart (servers.reboot_required until then), and a task can
     * carry plain inputs such as the addresses to release (tasks.input).
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('reboot_required')->default(false)->after('status');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('input')->nullable()->after('steps');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('input');
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('reboot_required');
        });
    }
};
