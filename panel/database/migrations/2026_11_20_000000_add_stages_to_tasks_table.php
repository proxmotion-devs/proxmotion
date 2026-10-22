<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Staged tasks (docs/adr/0032): the stage a create or reinstall is in and
            // what that stage remembers between runs (a Proxmox UPID, when a wait began).
            $table->string('stage', 40)->nullable()->after('steps');
            $table->json('stage_state')->nullable()->after('stage');
            // Incremented each time the task is queued again; older jobs of the task do nothing.
            $table->unsignedInteger('dispatch')->default(0)->after('stage_state');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
            $table->timestamp('deadline_at')->nullable()->after('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['stage', 'stage_state', 'dispatch', 'heartbeat_at', 'deadline_at']);
        });
    }
};
