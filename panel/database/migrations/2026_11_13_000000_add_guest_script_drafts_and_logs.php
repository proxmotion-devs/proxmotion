<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drafts of guest scripts and what each run printed (docs/adr/0023).
     */
    public function up(): void
    {
        // A row may now hold only a draft: the event keeps its default meanwhile.
        Schema::table('guest_scripts', function (Blueprint $table) {
            $table->text('content')->nullable()->change();
            $table->text('draft_content')->nullable()->after('content');
        });

        Schema::create('guest_script_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('os_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            // default, published or draft
            $table->string('source', 16);
            $table->boolean('trace')->default(false);
            // running, succeeded or failed
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            // Why the run failed when the script itself did not say (agent unreachable…).
            $table->text('message')->nullable();
            // The script that ran and its inputs (no secrets: the password arrives on standard input).
            $table->text('script');
            $table->json('inputs');
            // Encrypted, at most GuestScriptLog::MAX_OUTPUT_BYTES each (the end is kept).
            $table->longText('stdout')->nullable();
            $table->longText('stderr')->nullable();
            $table->boolean('output_truncated')->default(false);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['os_template_id', 'event', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_script_logs');

        Schema::table('guest_scripts', function (Blueprint $table) {
            $table->dropColumn('draft_content');
        });
    }
};
