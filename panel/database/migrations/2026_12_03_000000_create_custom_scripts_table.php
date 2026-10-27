<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Administrators' own scripts per OS template, run by hand or at events
     * (docs/adr/0043), and their runs in the guest script log.
     */
    public function up(): void
    {
        Schema::create('custom_scripts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('os_template_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->unsignedInteger('position')->default(0);
            // Listed under Run script on the server page.
            $table->boolean('manual')->default(false);
            // GuestScriptEvent values it runs after.
            $table->json('events');
            // Null until the first draft is published (docs/adr/0023).
            $table->text('content')->nullable();
            $table->text('draft_content')->nullable();
            $table->timestamps();

            $table->unique(['os_template_id', 'name']);
        });

        Schema::table('guest_script_logs', function (Blueprint $table) {
            // Null for a manual run or trial of a custom script.
            $table->string('event', 32)->nullable()->change();
            $table->foreignId('custom_script_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
            // Kept when the script is renamed or deleted.
            $table->string('script_name', 64)->nullable()->after('custom_script_id');
        });
    }

    public function down(): void
    {
        Schema::table('guest_script_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_script_id');
            $table->dropColumn('script_name');
        });

        Schema::dropIfExists('custom_scripts');
    }
};
