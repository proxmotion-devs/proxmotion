<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What an app's script printed inside the server (run.log), one row per
        // install attempt, uninstall, upgrade and action (docs/adr/0021).
        Schema::create('app_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('app_installation_id')->constrained()->cascadeOnDelete();
            // Null for an install attempt.
            $table->foreignId('app_run_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt')->nullable();
            // Encrypted; secret values masked; at most AppLog::MAX_BYTES.
            $table->longText('content')->nullable();
            // Bytes of run.log read so far.
            $table->unsignedBigInteger('offset')->default(0);
            // Bytes ever appended to content (the cursor of the live view).
            $table->unsignedBigInteger('length')->default(0);
            // The middle left out once content reached its limit: after head_bytes, omitted_bytes long.
            $table->unsignedInteger('head_bytes')->default(0);
            $table->unsignedBigInteger('omitted_bytes')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['app_installation_id', 'attempt']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_logs');
    }
};
